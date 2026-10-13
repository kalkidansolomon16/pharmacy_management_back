<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

/** A hospital with its own pharmacy registers both at once; the pharmacy gets its own licence check and login. */
class HospitalPharmacyRegistrationTest extends TestCase
{
    private function hospital(array $extra = []): array
    {
        return array_replace_recursive([
            'organization' => ['type' => 'hospital', 'name' => 'Entoto Hospital', 'license_number' => 'MOH/1/2018', 'phone' => '0115512345',
                'region' => 'Addis Ababa', 'city' => 'Addis Ababa', 'sub_city' => 'Gulele', 'address_line' => 'Entoto road',
                'latitude' => '9.05', 'longitude' => '38.76', 'opening_hours' => 'Always open', 'is_24_hours' => true],
            'admin' => ['name' => 'Hospital Admin', 'email' => 'admin@entoto.et', 'phone' => '0911000001', 'password' => 'Secret123', 'password_confirmation' => 'Secret123'],
        ], $extra);
    }

    private function pharmacy(array $extra = []): array
    {
        return ['has_pharmacy' => true, 'pharmacy' => array_replace_recursive([
            'name' => 'Entoto Hospital Pharmacy', 'license_number' => 'EFDA/RP/77/2019',
            'admin' => ['name' => 'Pharmacist Hana', 'email' => 'pharmacy@entoto.et', 'phone' => '0911000002', 'password' => 'Secret123', 'password_confirmation' => 'Secret123'],
        ], $extra)];
    }

    public function test_a_hospital_registers_its_own_pharmacy_at_the_same_time(): void
    {
        $this->seed(\Database\Seeders\PlatformSeeder::class);
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $this->postJson('/api/auth/register-organization', $this->hospital($this->pharmacy()))->assertCreated()
            ->assertJsonPath('data.user.tenant.in_house_pharmacies.0.name', 'Entoto Hospital Pharmacy')
            ->assertJsonPath('data.user.tenant.license_number', 'MOH/1/2018');

        $hospital = Tenant::where('name', 'Entoto Hospital')->firstOrFail();
        $pharmacy = Tenant::where('name', 'Entoto Hospital Pharmacy')->firstOrFail();
        $this->assertSame('pharmacy', $pharmacy->type);
        $this->assertSame('pending', $pharmacy->status); // verified separately, with its own EFDA licence
        $this->assertSame($hospital->id, $pharmacy->hospital_id);
        $this->assertSame('EFDA/RP/77/2019', $pharmacy->license_number);
        // Same place as the hospital
        $this->assertEquals([9.05, 38.76, 'Gulele'], [(float) $pharmacy->latitude, (float) $pharmacy->longitude, $pharmacy->sub_city]);
        $this->assertTrue($pharmacy->is_24_hours);
        $this->assertSame('pharmacy-pro', $pharmacy->subscription->plan->code); // its own free trial on a pharmacy plan

        // The pharmacist in charge has their own pharmacy login
        $pharmacist = User::where('email', 'pharmacy@entoto.et')->firstOrFail();
        $this->assertSame($pharmacy->id, $pharmacist->tenant_id);
        $this->assertTrue($pharmacist->hasRole(User::ROLE_PHARMACY_ADMIN));

        // The platform team is told about both, and sees the link
        $this->assertSame(2, $platform->notifications()->count());
        $this->actingAs($platform)->getJson("/api/admin/tenants/{$pharmacy->id}")->assertJsonPath('data.hospital.name', 'Entoto Hospital');
    }

    public function test_the_pharmacy_is_optional_and_only_for_hospitals(): void
    {
        $this->postJson('/api/auth/register-organization', $this->hospital(['has_pharmacy' => false, 'pharmacy' => ['name' => '']]))->assertCreated();
        $this->assertSame(0, Tenant::whereNotNull('hospital_id')->count());

        // A pharmacy registering does not create a second pharmacy
        $data = $this->hospital($this->pharmacy(['admin' => ['email' => 'p2@x.et', 'phone' => '0911000004']]));
        $data['organization'] = ['type' => 'pharmacy', 'name' => 'Corner Pharmacy', 'license_number' => 'EFDA/9'] + $data['organization'];
        $data['admin'] = ['email' => 'corner@x.et', 'phone' => '0911000003'] + $data['admin'];
        $this->postJson('/api/auth/register-organization', $data)->assertCreated();
        $this->assertSame(0, Tenant::whereNotNull('hospital_id')->count());
    }

    public function test_the_pharmacy_needs_its_own_licence_and_login(): void
    {
        $this->postJson('/api/auth/register-organization', $this->hospital($this->pharmacy(['license_number' => '', 'admin' => ['name' => '']])))
            ->assertUnprocessable()->assertJsonValidationErrors(['pharmacy.license_number', 'pharmacy.admin.name']);

        $this->postJson('/api/auth/register-organization', $this->hospital($this->pharmacy(['license_number' => 'MOH/1/2018', 'admin' => ['email' => 'admin@entoto.et']])))
            ->assertUnprocessable()->assertJsonValidationErrors(['pharmacy.license_number', 'pharmacy.admin.email']);

        $this->postJson('/api/auth/register-organization', $this->hospital($this->pharmacy(['admin' => ['phone' => '+251911000001']])))
            ->assertUnprocessable()->assertJsonValidationErrors(['pharmacy.admin.phone']);
        $this->assertSame(0, Tenant::count()); // nothing half-created
    }
}
