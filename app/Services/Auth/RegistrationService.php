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
            $specialtyIds = $org['specialty_ids'] ?? [];
            unset($org['specialty_ids']);
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
            // Hospitals & clinics choose their specialties while registering, so patients can find them from day one
            if (! $tenant->isPharmacy() && $specialtyIds) {
                $tenant->specialties()->sync(\App\Models\HospitalSpecialty::active()->whereIn('id', $specialtyIds)->pluck('id'));
            }
            // The hospital's own pharmacy, registered together with it
            if ($tenant->isHospital() && ! empty($data['has_pharmacy']) && ! empty($data['pharmacy'])) {
                $this->registerInHousePharmacy($tenant, $data['pharmacy'], $user);
            }

            return $user;
        });

        User::role(User::ROLE_SUPER_ADMIN)->get()->each->notify(new NewTenantRegistered($user->tenant));
        $user->tenant->inHousePharmacies()->get()->each(
            fn (Tenant $pharmacy) => User::role(User::ROLE_SUPER_ADMIN)->get()->each->notify(new NewTenantRegistered($pharmacy))
        );

        return $user;
    }

    /**
     * A hospital's in-house pharmacy: its own pharmacy account (own EFDA licence, own pharmacist in charge, own stock),
     * at the hospital's address, linked to the hospital. It is verified by the platform team like any pharmacy.
     */
    private function registerInHousePharmacy(Tenant $hospital, array $data, User $hospitalAdmin): Tenant
    {
        $phone = ReferenceGenerator::normalizePhone($data['admin']['phone']);
        if ($phone === $hospitalAdmin->phone) {
            throw \App\Exceptions\BusinessRuleException::withErrors('pharmacy.admin.phone', 'The pharmacist in charge needs their own mobile number, different from the hospital administrator.');
        }

        $pharmacy = Tenant::create([
            'name' => $data['name'],
            'type' => Tenant::TYPE_PHARMACY,
            'status' => 'pending',
            'hospital_id' => $hospital->id,
            'license_number' => $data['license_number'],
            'phone' => ! empty($data['phone']) ? ReferenceGenerator::normalizePhone($data['phone']) : $hospital->phone,
            'email' => $data['email'] ?? null,
            // Same place as the hospital
            'region' => $hospital->region, 'city' => $hospital->city, 'sub_city' => $hospital->sub_city, 'woreda' => $hospital->woreda,
            'address_line' => $hospital->address_line ?: "Inside {$hospital->name}",
            'latitude' => $hospital->latitude, 'longitude' => $hospital->longitude,
            'opening_hours' => $data['opening_hours'] ?? $hospital->opening_hours,
            'is_24_hours' => (bool) ($data['is_24_hours'] ?? $hospital->is_24_hours),
            'delivery_available' => (bool) ($data['delivery_available'] ?? false),
            'description' => "In-house pharmacy of {$hospital->name}.",
        ]);

        $pharmacist = User::create([
            'tenant_id' => $pharmacy->id,
            'name' => $data['admin']['name'],
            'email' => $data['admin']['email'],
            'phone' => $phone,
            'city' => $pharmacy->city,
            'password' => $data['admin']['password'],
        ]);
        $pharmacist->assignRole(User::ROLE_PHARMACY_ADMIN);
        app(\App\Services\Platform\SubscriptionService::class)->startTrial($pharmacy);
        \App\Models\Store::defaultFor($pharmacy->id);
        $this->logger->log('in_house_pharmacy_registered', $pharmacy, ['hospital_id' => $hospital->id], "{$hospital->name} registered its pharmacy {$pharmacy->name}");

        return $pharmacy;
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
