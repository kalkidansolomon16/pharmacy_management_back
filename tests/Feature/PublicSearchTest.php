<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicSearchTest extends TestCase
{
    public function test_search_shows_only_public_in_stock_offers_from_active_pharmacies(): void
    {
        $coartem = $this->medicine(['generic_name' => 'Artemether + Lumefantrine', 'brand_name' => 'Coartem']);

        $cheap = $this->tenant('pharmacy', ['name' => 'Bole Pharmacy', 'city' => 'Addis Ababa']);
        $pricey = $this->tenant('pharmacy', ['name' => 'Piassa Pharmacy', 'city' => 'Addis Ababa']);
        $suspended = $this->tenant('pharmacy', ['name' => 'Closed Pharmacy', 'status' => 'suspended']);
        $hawassa = $this->tenant('pharmacy', ['name' => 'Hawassa Pharmacy', 'city' => 'Hawassa']);
        $soldOut = $this->tenant('pharmacy', ['name' => 'Empty Pharmacy']);

        $this->listing($cheap, $coartem, [[50, 200]], 11);
        $this->listing($pricey, $coartem, [[50, 200]], 14);
        $this->listing($suspended, $coartem, [[50, 200]], 5);
        $this->listing($hawassa, $coartem, [[50, 200]], 9);
        $this->listing($soldOut, $coartem, [[50, -10]], 4); // only expired stock

        $this->getJson('/api/public/medicines?q=coartem&city=Addis+Ababa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pharmacies_count', 2)
            ->assertJsonPath('data.0.min_price', 11)
            ->assertJsonPath('data.0.offers.0.pharmacy.name', 'Bole Pharmacy')
            ->assertJsonMissingPath('data.0.offers.0.pharmacy.license_number');

        $this->getJson('/api/public/medicines?q=coartem')->assertJsonPath('data.0.pharmacies_count', 3);
        $this->getJson('/api/public/medicines?q=coartem&max_price=10')->assertJsonPath('data.0.offers.0.pharmacy.name', 'Hawassa Pharmacy');
    }

    public function test_public_pharmacy_directory_hides_inactive_pharmacies(): void
    {
        $this->tenant('pharmacy', ['name' => 'Open Pharmacy']);
        $this->tenant('pharmacy', ['name' => 'Pending Pharmacy', 'status' => 'pending']);
        $this->tenant('hospital', ['name' => 'A Hospital']);

        $this->getJson('/api/public/pharmacies')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Open Pharmacy');
    }

    public function test_nearby_pharmacies_are_within_the_radius_and_nearest_first(): void
    {
        // Distances from Meskel Square (9.0105, 38.7613)
        $this->tenant('pharmacy', ['name' => 'Two Km Pharmacy', 'latitude' => 9.0300, 'longitude' => 38.7613, 'is_24_hours' => true]);
        $this->tenant('pharmacy', ['name' => 'Close Pharmacy', 'latitude' => 9.0150, 'longitude' => 38.7650]);
        $this->tenant('pharmacy', ['name' => 'Ten Km Pharmacy', 'latitude' => 9.1000, 'longitude' => 38.7613]);
        $this->tenant('pharmacy', ['name' => 'Suspended Pharmacy', 'latitude' => 9.0110, 'longitude' => 38.7615, 'status' => 'suspended']);
        $this->tenant('pharmacy', ['name' => 'Unmapped Pharmacy']);
        $this->tenant('hospital', ['name' => 'Nearby Hospital', 'latitude' => 9.0110, 'longitude' => 38.7615]);

        $this->getJson('/api/public/pharmacies/nearby?lat=9.0105&lng=38.7613&radius_km=5')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Close Pharmacy')
            ->assertJsonPath('data.0.distance_km', 0.64)
            ->assertJsonPath('data.1.name', 'Two Km Pharmacy')
            ->assertJsonPath('data.1.distance_km', 2.17)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.license_number');

        $this->getJson('/api/public/pharmacies/nearby?lat=9.0105&lng=38.7613&radius_km=15')->assertJsonCount(3, 'data');
        $this->getJson('/api/public/pharmacies/nearby?lat=9.0105&lng=38.7613&open_24=1')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Two Km Pharmacy');
    }

    public function test_nearby_pharmacies_needs_a_location(): void
    {
        $this->getJson('/api/public/pharmacies/nearby')->assertUnprocessable()->assertJsonValidationErrors(['lat', 'lng']);
        $this->getJson('/api/public/pharmacies/nearby?lat=9&lng=38.7&radius_km=500')->assertJsonValidationErrors(['radius_km']);
    }

    public function test_pharmacy_catalogue_reports_live_stock(): void
    {
        $pharmacy = $this->tenant('pharmacy', ['name' => 'Bole Pharmacy']);
        $this->listing($pharmacy, $this->medicine(['generic_name' => 'Paracetamol']), [[30, 100], [5, -2]]);

        $this->getJson("/api/public/pharmacies/{$pharmacy->slug}/medicines")
            ->assertOk()
            ->assertJsonPath('data.0.medicine.generic_name', 'Paracetamol')
            ->assertJsonPath('data.0.available_quantity', 30); // the expired batch is excluded
    }
}
