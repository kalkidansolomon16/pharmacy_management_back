<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Prescriptions\PrescriptionService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/** Location-aware medicine search, prescription stockists and prescriber suggestions. */
class NearMeTest extends TestCase
{
    // Meskel Square, Addis Ababa
    private const LAT = 9.0105;

    private const LNG = 38.7613;

    public function test_medicine_search_near_me_lists_the_closest_pharmacies_first_within_the_radius(): void
    {
        $coartem = $this->medicine(['generic_name' => 'Artemether + Lumefantrine', 'brand_name' => 'Coartem']);
        $far = $this->tenant('pharmacy', ['name' => 'Far Cheap Pharmacy', 'latitude' => 9.0300, 'longitude' => 38.7613]); // ~2.2 km
        $near = $this->tenant('pharmacy', ['name' => 'Near Pharmacy', 'latitude' => 9.0150, 'longitude' => 38.7650]);     // ~0.6 km
        $outside = $this->tenant('pharmacy', ['name' => 'Bahir Dar Pharmacy', 'latitude' => 11.5936, 'longitude' => 37.3908]);
        $unmapped = $this->tenant('pharmacy', ['name' => 'Unmapped Pharmacy']);
        $this->listing($far, $coartem, [[50, 200]], 9);
        $this->listing($near, $coartem, [[50, 200]], 14);
        $this->listing($outside, $coartem, [[50, 200]], 5);
        $this->listing($unmapped, $coartem, [[50, 200]], 6);

        $this->getJson('/api/public/medicines?q=coartem&lat='.self::LAT.'&lng='.self::LNG.'&radius_km=5')
            ->assertOk()
            ->assertJsonPath('data.0.nearest_km', 0.64)
            ->assertJsonCount(2, 'data.0.offers')
            ->assertJsonPath('data.0.offers.0.pharmacy.name', 'Near Pharmacy')
            ->assertJsonPath('data.0.offers.0.pharmacy.distance_km', 0.64)
            ->assertJsonPath('data.0.offers.1.pharmacy.name', 'Far Cheap Pharmacy');

        // Without a location nothing changes: cheapest first across all pharmacies
        $this->getJson('/api/public/medicines?q=coartem')
            ->assertJsonPath('data.0.offers.0.pharmacy.name', 'Bahir Dar Pharmacy')
            ->assertJsonMissingPath('data.0.nearest_km');
    }

    public function test_a_location_needs_both_coordinates(): void
    {
        $this->getJson('/api/public/medicines?lat=9.01')->assertUnprocessable()->assertJsonValidationErrors('lng');
    }

    public function test_prescription_check_lists_the_nearest_pharmacies_with_every_item(): void
    {
        $hospital = $this->tenant('hospital');
        $doctor = $this->userWithRole(User::ROLE_DOCTOR, $hospital);
        $patient = $this->patient($hospital);
        $medicine = $this->medicine();
        foreach ([['Far Pharmacy', 9.0300], ['Near Pharmacy', 9.0150]] as [$name, $lat]) {
            $this->listing($this->tenant('pharmacy', ['name' => $name, 'latitude' => $lat, 'longitude' => self::LNG]), $medicine);
        }
        Auth::setUser($doctor);
        $rx = app(PrescriptionService::class)->issue($doctor, $patient, ['diagnosis' => 'Test', 'validity_days' => 30, 'items' => [
            ['medicine_id' => $medicine->id, 'dosage' => '1 tablet', 'frequency' => 'Once daily', 'duration_days' => 5, 'total_quantity' => 5],
        ]]);
        Auth::forgetUser();

        $this->postJson('/api/public/prescriptions/verify', ['code' => $rx->reference_code, 'phone' => $patient->phone, 'lat' => self::LAT, 'lng' => self::LNG])
            ->assertOk()
            ->assertJsonPath('data.pharmacies_with_all_items.0.name', 'Near Pharmacy')
            ->assertJsonPath('data.pharmacies_with_all_items.1.name', 'Far Pharmacy');
    }

    public function test_patients_can_find_nearby_hospitals_and_pharmacies_together(): void
    {
        $this->tenant('hospital', ['name' => 'Near Clinic', 'latitude' => 9.0150, 'longitude' => 38.7650, 'is_24_hours' => true, 'modules' => ['clinical', 'lab']]);
        $this->tenant('pharmacy', ['name' => 'Close Pharmacy', 'latitude' => 9.0300, 'longitude' => self::LNG]);
        $this->tenant('hospital', ['name' => 'Pending Hospital', 'latitude' => 9.0110, 'longitude' => self::LNG, 'status' => 'pending']);
        $this->tenant('hospital', ['name' => 'Unmapped Hospital']);
        $this->tenant('hospital', ['name' => 'Far Hospital', 'latitude' => 11.5936, 'longitude' => 37.3908]);

        $base = '/api/public/facilities/nearby?lat='.self::LAT.'&lng='.self::LNG.'&radius_km=5';

        $this->getJson($base)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Near Clinic')
            ->assertJsonPath('data.0.type', 'hospital')
            ->assertJsonPath('data.0.services', ['opd', 'emergency', 'laboratory'])
            ->assertJsonPath('data.1.type', 'pharmacy')
            ->assertJsonPath('meta.hospitals', 1)
            ->assertJsonMissingPath('data.0.license_number')
            ->assertJsonMissingPath('data.0.tin_number');

        $this->getJson($base.'&type=hospital')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Near Clinic');
        $this->getJson($base.'&type=pharmacy')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Close Pharmacy');
        $this->getJson($base.'&type=clinic')->assertUnprocessable();
    }

    public function test_prescribers_see_the_nearest_partner_pharmacies_with_distances(): void
    {
        $hospital = $this->tenant('hospital', ['latitude' => self::LAT, 'longitude' => self::LNG]);
        $doctor = $this->userWithRole(User::ROLE_DOCTOR, $hospital);
        $medicine = $this->medicine();
        $this->listing($this->tenant('pharmacy', ['name' => 'Far Cheap', 'latitude' => 9.0300, 'longitude' => self::LNG]), $medicine, price: 5);
        $this->listing($this->tenant('pharmacy', ['name' => 'Next Door', 'latitude' => 9.0110, 'longitude' => self::LNG]), $medicine, price: 9);

        $this->actingAs($doctor)->getJson("/api/medicines/{$medicine->id}/availability")
            ->assertOk()
            ->assertJsonPath('data.cheapest_nearby.0.pharmacy', 'Next Door')
            ->assertJsonPath('data.cheapest_nearby.0.distance_km', 0.06)
            ->assertJsonPath('data.cheapest_nearby.1.pharmacy', 'Far Cheap');
    }
}
