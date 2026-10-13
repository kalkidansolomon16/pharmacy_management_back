<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Tests\TestCase;

/** Organizations can put themselves on the map while registering. */
class OrganizationLocationTest extends TestCase
{
    private function registration(array $org): array
    {
        return [
            'organization' => $org + ['type' => 'pharmacy', 'name' => 'Pin Pharmacy '.uniqid(), 'license_number' => 'EFDA/RP/3/2018', 'phone' => '0115512345',
                'region' => 'Addis Ababa', 'city' => 'Addis Ababa'],
            'admin' => ['name' => 'Admin', 'email' => uniqid().'@test.et', 'phone' => '09'.random_int(10000000, 99999999), 'password' => 'Secret123', 'password_confirmation' => 'Secret123'],
        ];
    }

    public function test_the_map_pin_given_at_registration_is_saved(): void
    {
        $this->postJson('/api/auth/register-organization', $this->registration(['latitude' => '9.012345', 'longitude' => '38.761234']))
            ->assertCreated()
            ->assertJsonPath('data.user.tenant.latitude', 9.012345);

        $this->assertNotNull(Tenant::where('latitude', 9.012345)->first());
    }

    public function test_the_pin_is_optional_but_must_be_in_ethiopia(): void
    {
        $this->postJson('/api/auth/register-organization', $this->registration([]))->assertCreated();
        $this->postJson('/api/auth/register-organization', $this->registration(['latitude' => '', 'longitude' => '']))->assertCreated();

        // Nairobi is not in Ethiopia
        $this->postJson('/api/auth/register-organization', $this->registration(['latitude' => '-1.29', 'longitude' => '36.82']))
            ->assertUnprocessable()->assertJsonValidationErrors(['organization.latitude']);

        // Half a pin is not a location
        $this->postJson('/api/auth/register-organization', $this->registration(['latitude' => '9.01']))
            ->assertUnprocessable()->assertJsonValidationErrors(['organization.longitude']);
    }
}
