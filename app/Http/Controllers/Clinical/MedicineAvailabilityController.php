<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\PharmacyMedicine;
use App\Services\Catalog\PublicSearchService;
use App\Support\Geo;
use Illuminate\Http\Request;

/**
 * While prescribing: is it in our own pharmacy, and how many pharmacies nearby have it?
 */
class MedicineAvailabilityController extends Controller
{
    public function __invoke(Request $request, Medicine $medicine, PublicSearchService $search)
    {
        $tenant = $request->user()->tenant;

        $inHouse = null;
        if ($tenant?->hasModule('pharmacy')) {
            $listing = PharmacyMedicine::where('medicine_id', $medicine->id)->withAvailableQuantity()->first();
            $inHouse = ['listed' => (bool) $listing, 'available' => (int) ($listing?->available_quantity ?? 0), 'price' => $listing ? (float) $listing->price : null];
        }

        $origin = $tenant?->latitude !== null && $tenant?->longitude !== null ? ['lat' => (float) $tenant->latitude, 'lng' => (float) $tenant->longitude] : null;
        $offers = PharmacyMedicine::acrossTenants()->where('medicine_id', $medicine->id)
            ->where($search->offerConstraint(['city' => $tenant?->city]))
            ->with('tenant:id,name,sub_city,city,latitude,longitude')->orderBy('price')
            ->when(! $origin, fn ($q) => $q->limit(3))->get()
            // When the facility has a map location: nearest pharmacies first (then cheapest)
            ->when($origin, fn ($c) => $c->sortBy(fn ($o) => [Geo::from($origin, $o->tenant) ?? INF, (float) $o->price])->take(3)->values());
        $network = PharmacyMedicine::acrossTenants()->where('medicine_id', $medicine->id)->where($search->offerConstraint([]))->count();

        return $this->respond([
            'medicine_id' => $medicine->id,
            'in_house' => $inHouse,
            'nearby_count' => $offers->count() ? PharmacyMedicine::acrossTenants()->where('medicine_id', $medicine->id)->where($search->offerConstraint(['city' => $tenant?->city]))->count() : 0,
            'network_count' => $network,
            'cheapest_nearby' => $offers->map(fn ($o) => ['pharmacy_id' => $o->tenant_id, 'pharmacy' => $o->tenant->name, 'area' => $o->tenant->sub_city ?? $o->tenant->city, 'price' => (float) $o->price, 'distance_km' => Geo::from($origin, $o->tenant)])->values(),
        ]);
    }
}
