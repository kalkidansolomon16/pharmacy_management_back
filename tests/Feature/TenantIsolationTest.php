<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    public function test_pharmacy_cannot_see_or_touch_another_pharmacys_inventory(): void
    {
        $mine = $this->tenant();
        $theirs = $this->tenant();
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $mine);
        $myListing = $this->listing($mine);
        $theirListing = $this->listing($theirs);

        Sanctum::actingAs($admin);

        $this->getJson('/api/inventory')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $myListing->id)
            ->assertJsonPath('data.0.available_quantity', 100)
            ->assertJsonPath('data.0.stock_status', 'in_stock');

        $this->getJson("/api/inventory/{$theirListing->id}")->assertNotFound();
        $this->putJson("/api/inventory/{$theirListing->id}", ['price' => 1])->assertNotFound();
        $this->postJson("/api/inventory/{$theirListing->id}/batches", [
            'batch_number' => 'X', 'quantity' => 1, 'expiry_date' => today()->addYear()->toDateString(), 'purchase_price' => 1,
        ])->assertNotFound();
    }

    public function test_pos_cannot_sell_another_pharmacys_stock(): void
    {
        $mine = $this->tenant();
        $theirs = $this->tenant();
        $staff = $this->userWithRole(User::ROLE_STAFF, $mine);
        $theirListing = $this->listing($theirs);

        Sanctum::actingAs($staff);
        $this->postJson('/api/pos/sales', [
            'items' => [['pharmacy_medicine_id' => $theirListing->id, 'quantity' => 1]],
            'payment_method' => 'cash',
        ])->assertUnprocessable();
    }

    public function test_tenant_admin_only_manages_own_team(): void
    {
        $mine = $this->tenant();
        $theirs = $this->tenant();
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $mine);
        $colleague = $this->userWithRole(User::ROLE_STAFF, $mine);
        $stranger = $this->userWithRole(User::ROLE_STAFF, $theirs);

        Sanctum::actingAs($admin);
        $this->getJson('/api/users')->assertOk()->assertJsonCount(2, 'data');
        $this->putJson("/api/users/{$stranger->id}", ['name' => 'X', 'email' => 'x@x.et', 'role' => 'staff'])->assertForbidden();
        $this->deleteJson("/api/users/{$colleague->id}")->assertOk();

        // Cannot grant roles from another tenant type, or super admin
        $this->postJson('/api/users', [
            'name' => 'Dr X', 'email' => 'drx@x.et', 'role' => User::ROLE_DOCTOR, 'password' => 'Secret123',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_audit_log_is_tenant_scoped(): void
    {
        $mine = $this->tenant();
        $theirs = $this->tenant();
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $mine);
        $otherAdmin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $theirs);
        $listing = $this->listing($theirs);

        Sanctum::actingAs($otherAdmin);
        $this->putJson("/api/inventory/{$listing->id}", ['price' => 99])->assertOk();

        Sanctum::actingAs($admin);
        $this->getJson('/api/activity-logs')->assertOk()->assertJsonCount(0, 'data');
    }
}
