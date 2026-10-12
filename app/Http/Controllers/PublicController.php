<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\MedicineCategoryResource;
use App\Http\Resources\PharmacyMedicineResource;
use App\Http\Resources\PrescriptionResource;
use App\Http\Resources\PublicFacilityResource;
use App\Http\Resources\PublicPharmacyResource;
use App\Http\Resources\SearchResultResource;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\Order;
use App\Models\PharmacyMedicine;
use App\Models\Tenant;
use App\Services\Catalog\PublicSearchService;
use App\Services\Prescriptions\PrescriptionService;
use App\Support\Geo;
use App\Support\ReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function __construct(private PublicSearchService $search) {}

    public function searchMedicines(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'sub_city' => ['nullable', 'string', 'max:80'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'in_stock' => ['nullable', 'boolean'],
            'rx' => ['nullable', 'boolean'],
            'open_24' => ['nullable', 'boolean'],
            'delivery' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:relevance,price_asc,price_desc,pharmacies,nearest'],
            // Near me: offers within radius_km of the user, nearest first
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.5', 'max:50'],
        ]);

        return SearchResultResource::collection($this->search->search($filters, $this->perPage(12)));
    }

    public function categories()
    {
        return MedicineCategoryResource::collection(
            MedicineCategory::where('review_status', 'approved')->withCount(['medicines' => fn ($q) => $q->where('is_active', true)->where('review_status', 'approved')])->orderBy('name')->get()
        );
    }

    public function pharmacies(Request $request)
    {
        $pharmacies = Tenant::pharmacies()->active()
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('address_line', 'like', "%{$term}%")))
            ->when($request->query('city'), fn ($q, $city) => $q->where('city', $city))
            ->when($request->query('sub_city'), fn ($q, $sub) => $q->where('sub_city', $sub))
            ->when($request->boolean('open_24'), fn ($q) => $q->where('is_24_hours', true))
            ->when($request->boolean('delivery'), fn ($q) => $q->where('delivery_available', true))
            ->withCount(['pharmacyMedicines' => fn ($q) => $q->where('is_public', true)])
            ->orderBy('name')
            ->paginate($this->perPage(12))
            ->withQueryString();

        return PublicPharmacyResource::collection($pharmacies);
    }

    /**
     * Active pharmacies within `radius_km` of a point, nearest first. A bounding
     * box narrows the rows in SQL (plain comparisons, so it works on MySQL and
     * SQLite alike); the exact great-circle distance is then computed here.
     */
    public function nearbyPharmacies(Request $request)
    {
        $filters = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.5', 'max:50'],
            'open_24' => ['nullable', 'boolean'],
            'delivery' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $lat = (float) $filters['lat'];
        $lng = (float) $filters['lng'];
        $radius = (float) ($filters['radius_km'] ?? 5);
        $latDelta = $radius / 111.32;
        $lngDelta = $radius / (111.32 * max(cos(deg2rad($lat)), 0.01));

        $pharmacies = Tenant::pharmacies()->active()
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta])
            ->when($request->boolean('open_24'), fn ($q) => $q->where('is_24_hours', true))
            ->when($request->boolean('delivery'), fn ($q) => $q->where('delivery_available', true))
            ->withCount(['pharmacyMedicines' => fn ($q) => $q->where('is_public', true)])
            ->get()
            ->each(fn (Tenant $p) => $p->setAttribute('distance_km', round(self::distanceKm($lat, $lng, $p->latitude, $p->longitude), 2)))
            ->filter(fn (Tenant $p) => $p->distance_km <= $radius)
            ->sortBy('distance_km')
            ->take($filters['limit'] ?? 50)
            ->values();

        return PublicPharmacyResource::collection($pharmacies)
            ->additional(['meta' => ['lat' => $lat, 'lng' => $lng, 'radius_km' => $radius, 'total' => $pharmacies->count()]]);
    }

    /**
     * Pharmacies and/or hospitals & clinics near a point, nearest first (?type=pharmacy|hospital|all).
     * Same rules as nearbyPharmacies(): active organizations with a map location, within radius_km.
     */
    public function nearbyFacilities(Request $request)
    {
        $filters = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.5', 'max:50'],
            'type' => ['nullable', 'in:pharmacy,hospital,all'],
            'open_24' => ['nullable', 'boolean'],
            'delivery' => ['nullable', 'boolean'], // pharmacies that deliver (hospitals drop out)
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $lat = (float) $filters['lat'];
        $lng = (float) $filters['lng'];
        $radius = (float) ($filters['radius_km'] ?? 5);
        $type = $filters['type'] ?? 'all';

        $facilities = Geo::withinBox(Tenant::active(), $lat, $lng, $radius)
            ->when($type !== 'all', fn ($q) => $q->where('type', $type), fn ($q) => $q->whereIn('type', [Tenant::TYPE_PHARMACY, Tenant::TYPE_HOSPITAL]))
            ->when($request->boolean('open_24'), fn ($q) => $q->where('is_24_hours', true))
            ->when($request->boolean('delivery'), fn ($q) => $q->where('type', Tenant::TYPE_PHARMACY)->where('delivery_available', true))
            ->withCount(['pharmacyMedicines' => fn ($q) => $q->where('is_public', true)])
            ->get()
            ->each(fn (Tenant $t) => $t->setAttribute('distance_km', round(Geo::distanceKm($lat, $lng, (float) $t->latitude, (float) $t->longitude), 2)))
            ->filter(fn (Tenant $t) => $t->distance_km <= $radius)
            ->sortBy('distance_km')
            ->take($filters['limit'] ?? 50)
            ->values();

        return PublicFacilityResource::collection($facilities)->additional(['meta' => [
            'lat' => $lat, 'lng' => $lng, 'radius_km' => $radius, 'type' => $type, 'total' => $facilities->count(),
            'pharmacies' => $facilities->where('type', Tenant::TYPE_PHARMACY)->count(),
            'hospitals' => $facilities->where('type', Tenant::TYPE_HOSPITAL)->count(),
        ]]);
    }

    /** Haversine distance between two points, in kilometres. */
    private static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function pharmacy(string $slug)
    {
        $pharmacy = Tenant::pharmacies()->active()->where('slug', $slug)
            ->withCount(['pharmacyMedicines' => fn ($q) => $q->where('is_public', true)])
            ->firstOrFail();

        return new PublicPharmacyResource($pharmacy);
    }

    public function pharmacyMedicines(Request $request, string $slug)
    {
        $pharmacy = Tenant::pharmacies()->active()->where('slug', $slug)->firstOrFail();

        $listings = PharmacyMedicine::select('pharmacy_medicines.*') // before the aggregate, which adds its own column
            ->where('pharmacy_medicines.tenant_id', $pharmacy->id)
            ->where('pharmacy_medicines.is_public', true)
            ->whereHas('medicine', fn ($m) => $m->where('is_active', true)
                ->search($request->query('q'))
                ->when($request->query('category'), fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug))))
            ->when($request->boolean('in_stock'), fn ($q) => $q->whereHas('batches', fn ($b) => $b->sellable()))
            ->with('medicine.category')
            ->withAvailableQuantity()
            ->join('medicines', 'medicines.id', '=', 'pharmacy_medicines.medicine_id')
            ->orderBy('medicines.generic_name')
            ->paginate($this->perPage(24))
            ->withQueryString();

        return PharmacyMedicineResource::collection($listings);
    }

    public function stats(): JsonResponse
    {
        $pharmacies = Tenant::pharmacies()->active();

        return $this->respond([
            'pharmacies' => (clone $pharmacies)->count(),
            'hospitals' => Tenant::where('type', 'hospital')->active()->count(),
            'medicines' => Medicine::where('is_active', true)->count(),
            'cities' => (clone $pharmacies)->distinct()->count('city'),
            'orders_completed' => Order::whereIn('status', Order::FULFILLED_STATUSES)->count(),
        ]);
    }

    public function meta(): JsonResponse
    {
        return $this->respond(config('ethiopia') + [
            'cities_with_pharmacies' => Tenant::pharmacies()->active()->distinct()->orderBy('city')->pluck('city')->filter()->values(),
        ]);
    }

    public function verifyPrescription(Request $request, PrescriptionService $prescriptions): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'phone' => ['required', 'string', 'max:20'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ]);

        try {
            $prescription = $prescriptions->findByCode($data['code']);
        } catch (BusinessRuleException) {
            $prescription = null;
        }

        // One message for "unknown code" and "wrong phone", so codes cannot be probed
        if (! $prescription || ReferenceGenerator::normalizePhone($data['phone']) !== ReferenceGenerator::normalizePhone($prescription->patient->phone)) {
            return response()->json(['message' => 'No prescription matches that code and phone number.'], 404);
        }

        $stockists = $prescription->isDispensable()
            ? $this->search->pharmaciesStockingAll($prescription->items->pluck('medicine_id'), 5, PublicSearchService::origin($data + ['radius_km' => 50]))
            : collect();

        return $this->respond([
            'prescription' => (new PrescriptionResource($prescription))->asPublic()->resolve(),
            'pharmacies_with_all_items' => PublicPharmacyResource::collection($stockists)->resolve(),
        ]);
    }
}
