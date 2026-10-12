<?php

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewTenantRegistered;
use App\Notifications\TenantStatusChanged;
use App\Services\ActivityLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    public function __construct(private ActivityLogger $logger) {}

    public function registerCustomer(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => ReferenceGenerator::normalizePhone($data['phone']),
            'city' => $data['city'] ?? null,
            'address' => $data['address'] ?? null,
            'locale' => $data['locale'] ?? 'en',
            'password' => $data['password'],
        ]);
        $user->assignRole(User::ROLE_CUSTOMER);
        $this->logger->log('registered', $user, [], "Customer {$user->name} registered");

        return $user;
    }

    /**
     * Pharmacy / hospital self-onboarding. The tenant stays "pending" until the platform team verifies its licence.
     */
    public function registerOrganization(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $org = $data['organization'];
            $tenant = Tenant::create([
                ...$org,
                'phone' => ReferenceGenerator::normalizePhone($org['phone']),
                'status' => 'pending',
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $data['admin']['name'],
                'email' => $data['admin']['email'],
                'phone' => ReferenceGenerator::normalizePhone($data['admin']['phone']),
                'city' => $tenant->city,
                'password' => $data['admin']['password'],
            ]);
            $user->assignRole($tenant->isPharmacy() ? User::ROLE_PHARMACY_ADMIN : User::ROLE_HOSPITAL_ADMIN);
            app(\App\Services\Platform\SubscriptionService::class)->startTrial($tenant);
            \App\Models\Store::defaultFor($tenant->id);

            return $user;
        });

        User::role(User::ROLE_SUPER_ADMIN)->get()->each->notify(new NewTenantRegistered($user->tenant));

        return $user;
    }

    public function changeTenantStatus(Tenant $tenant, string $status, ?string $reason = null): Tenant
    {
        $tenant->update([
            'status' => $status,
            'approved_at' => $status === 'active' ? ($tenant->approved_at ?? now()) : $tenant->approved_at,
            // A reason only explains a rejection or suspension
            'status_reason' => in_array($status, ['rejected', 'suspended'], true) ? ($reason ?: null) : null,
            'status_changed_at' => now(),
        ]);

        $tenant->users()->get()->each->notify(new TenantStatusChanged($tenant));

        return $tenant;
    }

    /**
     * A rejected organization corrects its details and asks the platform team to look again.
     */
    public function resubmit(Tenant $tenant, array $changes, ?string $note = null): Tenant
    {
        if (isset($changes['phone'])) {
            $changes['phone'] = ReferenceGenerator::normalizePhone($changes['phone']);
        }
        $previousReason = $tenant->status_reason;
        $tenant->update([...$changes, 'status' => 'pending', 'status_reason' => null, 'status_changed_at' => now()]);
        $this->logger->log('tenant_resubmitted', $tenant, ['previous_reason' => $previousReason, 'note' => $note], "{$tenant->name} corrected its details and asked for review again");

        User::role(User::ROLE_SUPER_ADMIN)->get()->each->notify(new NewTenantRegistered($tenant, resubmitted: true, note: $note));

        return $tenant;
    }
}
