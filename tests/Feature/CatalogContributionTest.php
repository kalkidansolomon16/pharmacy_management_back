<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\User;
use App\Notifications\CatalogItemReviewed;
use App\Notifications\CatalogItemSubmitted;
use Tests\TestCase;

/** Pharmacies adding medicines & categories, controlled by role permissions and individual grants. */
class CatalogContributionTest extends TestCase
{
    private function medicinePayload(array $overrides = []): array
    {
        return $overrides + [
            'generic_name' => 'Zinc Oxide', 'strength' => '10%', 'dosage_form' => 'Ointment', 'unit' => 'tube',
            'prescription_required' => false, 'is_controlled' => false,
        ];
    }

    public function test_a_pharmacy_admin_adds_a_medicine_that_only_its_pharmacy_can_use_until_approved(): void
    {
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $mine = $this->tenant('pharmacy');
        $other = $this->tenant('pharmacy');
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $mine);
        $otherAdmin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $other);

        $id = $this->actingAs($admin)->postJson('/api/medicines', $this->medicinePayload())
            ->assertCreated()
            ->assertJsonPath('data.review_status', 'pending')
            ->assertJsonPath('data.is_own', true)
            ->json('data.id');
        $this->assertTrue($platform->notifications()->where('type', CatalogItemSubmitted::class)->exists());

        // Usable at once by its own pharmacy...
        $this->actingAs($admin)->postJson('/api/inventory', ['medicine_id' => $id, 'price' => 45, 'reorder_level' => 5])->assertCreated();
        // ...but invisible to other pharmacies and to prescribers until approved
        $this->actingAs($otherAdmin)->getJson('/api/medicines?search=zinc')->assertJsonCount(0, 'data');
        $this->actingAs($otherAdmin)->postJson('/api/inventory', ['medicine_id' => $id, 'price' => 40, 'reorder_level' => 5])->assertUnprocessable();

        $this->actingAs($platform)->postJson("/api/admin/catalog/medicines/{$id}/review", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.review_status', 'approved');
        $this->assertTrue($admin->notifications()->where('type', CatalogItemReviewed::class)->exists());

        $this->actingAs($otherAdmin)->getJson('/api/medicines?search=zinc')->assertJsonCount(1, 'data');
        $this->actingAs($otherAdmin)->postJson('/api/inventory', ['medicine_id' => $id, 'price' => 40, 'reorder_level' => 5])->assertCreated();
    }

    public function test_contributors_cannot_change_shared_medicines_or_other_pharmacies_entries(): void
    {
        $shared = $this->medicine(); // platform-made, approved
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $this->tenant('pharmacy'));
        $otherAdmin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $this->tenant('pharmacy'));

        $this->actingAs($admin)->putJson("/api/medicines/{$shared->id}", $this->medicinePayload())->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/medicines/{$shared->id}")->assertForbidden();

        $id = $this->actingAs($otherAdmin)->postJson('/api/medicines', $this->medicinePayload())->json('data.id');
        $this->actingAs($admin)->putJson("/api/medicines/{$id}", $this->medicinePayload(['strength' => '20%']))->assertForbidden();

        // Its own pending entry can be corrected
        $this->actingAs($otherAdmin)->putJson("/api/medicines/{$id}", $this->medicinePayload(['strength' => '15%']))->assertOk();
    }

    public function test_duplicates_of_existing_medicines_are_refused(): void
    {
        $this->medicine(['generic_name' => 'Paracetamol', 'strength' => '500mg', 'dosage_form' => 'Tablet']);
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $this->tenant('pharmacy'));

        $this->actingAs($admin)->postJson('/api/medicines', $this->medicinePayload(['generic_name' => 'paracetamol', 'strength' => '500MG', 'dosage_form' => 'Tablet', 'unit' => 'tablet']))
            ->assertUnprocessable()->assertJsonValidationErrors('generic_name');
    }

    public function test_pharmacists_need_the_permission_which_their_admin_can_grant(): void
    {
        $pharmacy = $this->tenant('pharmacy');
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $pharmacy);
        $pharmacist = $this->userWithRole(User::ROLE_STAFF, $pharmacy);

        $this->actingAs($pharmacist)->postJson('/api/medicines', $this->medicinePayload())->assertForbidden();
        $this->actingAs($pharmacist)->postJson('/api/categories', ['name' => 'Skin care'])->assertForbidden();

        $this->actingAs($admin)->getJson('/api/users')->assertJsonPath('meta.grantable_permissions.0.value', 'catalog.contribute');
        $profile = ['name' => $pharmacist->name, 'email' => $pharmacist->email, 'role' => User::ROLE_STAFF];
        $this->actingAs($admin)->putJson("/api/users/{$pharmacist->id}", $profile + ['permissions' => ['tenants.manage']])
            ->assertUnprocessable(); // only grantable permissions
        $this->actingAs($admin)->putJson("/api/users/{$pharmacist->id}", $profile + ['permissions' => ['catalog.contribute']])
            ->assertOk()->assertJsonPath('data.extra_permissions', ['catalog.contribute']);

        $this->actingAs($pharmacist->fresh())->postJson('/api/medicines', $this->medicinePayload())->assertCreated();

        // Taking it away again
        $this->actingAs($admin)->putJson("/api/users/{$pharmacist->id}", $profile + ['permissions' => []])->assertOk();
        $this->actingAs($pharmacist->fresh())->postJson('/api/categories', ['name' => 'Baby care'])->assertForbidden();
    }

    public function test_pharmacy_categories_stay_private_until_approved_and_rejection_needs_a_reason(): void
    {
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $this->tenant('pharmacy'));

        $id = $this->actingAs($admin)->postJson('/api/categories', ['name' => 'Skin care'])
            ->assertCreated()->assertJsonPath('data.review_status', 'pending')->json('data.id');
        $this->getJson('/api/public/categories')->assertJsonMissing(['name' => 'Skin care']);

        $this->actingAs($platform)->postJson("/api/admin/catalog/categories/{$id}/review", ['decision' => 'reject'])->assertUnprocessable();
        $this->actingAs($platform)->postJson("/api/admin/catalog/categories/{$id}/review", ['decision' => 'reject', 'note' => 'Use the existing Dermatology category'])
            ->assertOk()->assertJsonPath('data.review_status', 'rejected');
        $this->assertSame('rejected', MedicineCategory::find($id)->review_status);

        $this->actingAs($admin)->postJson("/api/admin/catalog/categories/{$id}/review", ['decision' => 'approve'])->assertForbidden();
    }

    public function test_doctors_can_only_prescribe_shared_medicines(): void
    {
        $pending = Medicine::create($this->medicinePayload() + ['review_status' => 'pending', 'created_by_tenant_id' => $this->tenant('pharmacy')->id]);
        $hospital = $this->tenant('hospital');
        $doctor = $this->userWithRole(User::ROLE_DOCTOR, $hospital);
        $patient = $this->patient($hospital);

        $this->actingAs($doctor)->postJson('/api/prescriptions', [
            'patient_id' => $patient->id, 'diagnosis' => 'Eczema', 'validity_days' => 30,
            'items' => [['medicine_id' => $pending->id, 'dosage' => 'Thin layer', 'frequency' => 'Twice daily', 'duration_days' => 7, 'total_quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.medicine_id');
    }
}
