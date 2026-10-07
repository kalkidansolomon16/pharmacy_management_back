<?php

namespace Tests;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Patient;
use App\Models\PharmacyMedicine;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tenant(string $type = 'pharmacy', array $attributes = []): Tenant
    {
        return Tenant::create($attributes + [
            'name' => ucfirst($type).' '.fake()->unique()->word(),
            'type' => $type,
            'status' => 'active',
            'city' => 'Addis Ababa',
            'sub_city' => 'Bole',
            'license_number' => 'EFDA/'.fake()->numerify('####'),
        ]);
    }

    protected function userWithRole(string $role, ?Tenant $tenant = null, array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['tenant_id' => $tenant?->id]);
        $user->assignRole($role);

        return $user;
    }

    protected function medicine(array $attributes = []): Medicine
    {
        return Medicine::create($attributes + [
            'generic_name' => fake()->unique()->word().'cillin',
            'dosage_form' => 'Tablet',
            'strength' => '500mg',
            'unit' => 'tablet',
            'prescription_required' => false,
        ]);
    }

    /**
     * A pharmacy listing with batches given as [[quantity, expiry 'Y-m-d' or days-from-today], ...].
     */
    protected function listing(Tenant $pharmacy, ?Medicine $medicine = null, array $batches = [[100, 365]], float $price = 10): PharmacyMedicine
    {
        $listing = PharmacyMedicine::withoutGlobalScopes()->create([
            'tenant_id' => $pharmacy->id,
            'medicine_id' => ($medicine ?? $this->medicine())->id,
            'price' => $price,
            'reorder_level' => 10,
            'is_public' => true,
        ]);

        foreach ($batches as $i => [$qty, $expiry]) {
            MedicineBatch::withoutGlobalScopes()->create([
                'tenant_id' => $pharmacy->id,
                'pharmacy_medicine_id' => $listing->id,
                'batch_number' => "B{$listing->id}-{$i}",
                'initial_quantity' => $qty,
                'quantity' => $qty,
                'expiry_date' => is_int($expiry) ? today()->addDays($expiry) : $expiry,
                'purchase_price' => $price * 0.7,
            ]);
        }

        return $listing->load('medicine');
    }

    protected function patient(Tenant $hospital, array $attributes = []): Patient
    {
        return Patient::withoutGlobalScopes()->create($attributes + [
            'tenant_id' => $hospital->id,
            'full_name' => 'Abebe Kebede',
            'phone' => '+251911223344',
            'gender' => 'male',
        ]);
    }
}
