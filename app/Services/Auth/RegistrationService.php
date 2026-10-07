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

            return $user;
        });

        User::role(User::ROLE_SUPER_ADMIN)->get()->each->notify(new NewTenantRegistered($user->tenant));

        return $user;
    }

    public function changeTenantStatus(Tenant $tenant, string $status): Tenant
    {
        $tenant->update([
            'status' => $status,
            'approved_at' => $status === 'active' ? ($tenant->approved_at ?? now()) : $tenant->approved_at,
        ]);

        $tenant->users()->get()->each->notify(new TenantStatusChanged($tenant));

        return $tenant;
    }
}
