<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    public function test_guests_get_a_json_401(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized()->assertExactJson(['message' => 'Please sign in to continue.']);
    }

    public function test_role_boundaries(): void
    {
        $pharmacy = $this->tenant();
        $hospital = $this->tenant('hospital');
        $staff = $this->userWithRole(User::ROLE_STAFF, $pharmacy);
        $doctor = $this->userWithRole(User::ROLE_DOCTOR, $hospital);
        $customer = $this->userWithRole(User::ROLE_CUSTOMER);

        Sanctum::actingAs($staff);
        $this->getJson('/api/inventory')->assertOk();
        $this->postJson('/api/inventory', ['medicine_id' => $this->medicine()->id, 'price' => 5])->assertForbidden(); // staff can't change catalogue/prices
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/patients')->assertForbidden();
        $this->getJson('/api/admin/tenants')->assertForbidden();

        Sanctum::actingAs($doctor);
        $this->getJson('/api/patients')->assertOk();
        $this->getJson('/api/inventory')->assertForbidden();
        $this->postJson('/api/medicines', ['generic_name' => 'X'])->assertForbidden();

        Sanctum::actingAs($customer);
        $this->getJson('/api/my/orders')->assertOk();
        $this->getJson('/api/orders')->assertForbidden();
    }

    public function test_customer_cannot_read_someone_elses_order(): void
    {
        $pharmacy = $this->tenant();
        $listing = $this->listing($pharmacy);
        $alice = $this->userWithRole(User::ROLE_CUSTOMER);
        $bob = $this->userWithRole(User::ROLE_CUSTOMER);

        Sanctum::actingAs($alice);
        $id = $this->postJson('/api/my/orders', [
            'pharmacy_id' => $pharmacy->id,
            'items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 1]],
            'fulfillment' => 'pickup', 'payment_method' => 'cash',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($bob);
        $this->getJson("/api/my/orders/{$id}")->assertForbidden();
        $this->postJson("/api/my/orders/{$id}/cancel")->assertForbidden();
    }

    public function test_pending_organization_is_blocked_until_approved(): void
    {
        $this->postJson('/api/auth/register-organization', [
            'organization' => [
                'name' => 'Gondar Fasil Pharmacy', 'type' => 'pharmacy', 'license_number' => 'EFDA/RP/1/2017',
                'phone' => '0581110022', 'region' => 'Amhara', 'city' => 'Gondar',
            ],
            'admin' => [
                'name' => 'Fikru Desta', 'email' => 'fikru@example.com', 'phone' => '0918440011',
                'password' => 'Secret123', 'password_confirmation' => 'Secret123',
            ],
        ])->assertCreated()->assertJsonPath('data.user.tenant.status', 'pending');

        $user = User::where('email', 'fikru@example.com')->first();
        $superAdmin = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        Sanctum::actingAs($user);
        $this->getJson('/api/dashboard')->assertForbidden()->assertJsonPath('code', 'tenant_pending');
        $this->getJson('/api/auth/me')->assertOk();

        Sanctum::actingAs($superAdmin);
        $this->patchJson("/api/admin/tenants/{$user->tenant_id}/status", ['status' => 'active'])->assertOk();

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.type', 'pharmacy');
    }

    public function test_validation_errors_use_the_standard_shape(): void
    {
        $this->postJson('/api/auth/register', ['email' => 'not-an-email', 'phone' => '12345'])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['name', 'email', 'phone', 'password']]);
    }

    public function test_login_returns_token_and_permissions(): void
    {
        $user = $this->userWithRole(User::ROLE_CUSTOMER, null, ['email' => 'abebe@example.com']);

        $this->postJson('/api/auth/login', ['email' => 'abebe@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => 'ABEBE@example.com', 'password' => 'Password@123'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'role', 'permissions']]])
            ->assertJsonPath('data.user.permissions', ['orders.place']);
    }
}
