<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/** Every organization has its own email address. */
class OrganizationEmailUniqueTest extends TestCase
{
    private function registration(string $orgEmail, string $adminEmail): array
    {
        return [
            'organization' => ['type' => 'pharmacy', 'name' => 'Pharmacy '.uniqid(), 'license_number' => 'EFDA/RP/1/2018', 'phone' => '0115512345',
                'email' => $orgEmail, 'region' => 'Addis Ababa', 'city' => 'Addis Ababa'],
            'admin' => ['name' => 'Admin', 'email' => $adminEmail, 'phone' => '09'.random_int(10000000, 99999999), 'password' => 'Secret123', 'password_confirmation' => 'Secret123'],
        ];
    }

    public function test_two_organizations_cannot_register_with_the_same_email(): void
    {
        $this->postJson('/api/auth/register-organization', $this->registration('info@shared.et', 'one@test.et'))->assertCreated();

        // Different capitals are still the same address
        $this->postJson('/api/auth/register-organization', $this->registration('INFO@Shared.et', 'two@test.et'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization.email')
            ->assertJsonPath('errors', fn ($e) => str_contains($e['organization.email'][0], 'Another organization already uses this email'));

        // Leaving it empty is fine for any number of organizations
        $this->postJson('/api/auth/register-organization', $this->registration('', 'three@test.et'))->assertCreated();
        $this->postJson('/api/auth/register-organization', $this->registration('', 'four@test.et'))->assertCreated();
    }

    public function test_an_organization_cannot_take_another_ones_email_but_can_keep_its_own(): void
    {
        $this->tenant('pharmacy', ['email' => 'taken@pharmacy.et']);
        $mine = $this->tenant('pharmacy', ['email' => 'mine@pharmacy.et']);
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $mine);

        $this->actingAs($admin)->putJson('/api/organization', ['email' => 'taken@pharmacy.et'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->actingAs($admin)->putJson('/api/organization', ['email' => 'mine@pharmacy.et', 'name' => 'Renamed'])->assertOk();

        // The platform team editing an organization follows the same rule
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $this->actingAs($platform)->putJson("/api/admin/tenants/{$mine->id}", ['email' => 'taken@pharmacy.et'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->actingAs($platform)->putJson("/api/admin/tenants/{$mine->id}", ['email' => 'mine@pharmacy.et'])->assertOk();
    }

    public function test_resubmitting_cannot_reuse_another_organizations_email(): void
    {
        $this->tenant('pharmacy', ['email' => 'taken@pharmacy.et']);
        $rejected = $this->tenant('pharmacy', ['status' => 'rejected']);
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $rejected);
        $body = ['name' => 'Fixed', 'license_number' => 'EFDA/RP/2/2018', 'phone' => '0911223355'];

        $this->actingAs($admin)->postJson('/api/organization/resubmit', $body + ['email' => 'taken@pharmacy.et'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->actingAs($admin)->postJson('/api/organization/resubmit', $body + ['email' => 'own@pharmacy.et'])->assertOk();
    }
}
