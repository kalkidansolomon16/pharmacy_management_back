<?php

namespace App\Services\Catalog;

use App\Models\Medicine;
use App\Models\PharmacyMedicine;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Geo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Public "find my medicine" search: which pharmacies have it, at what price, in stock?
 */
class PublicSearchService
{
    public function search(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        $offers = $this->offerConstraint($filters);

        $query = Medicine::query()
            ->where('is_active', true)
            ->where('review_status', '!=', 'rejected') // pending entries only appear through their own pharmacy's stock
            ->search($filters['q'] ?? null)
            ->when($filters['category'] ?? null, fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when(isset($filters['rx']), fn ($q) => $q->where('prescription_required', (bool) $filters['rx']))
            ->whereHas('listings', $offers)
            ->withMin(['listings as min_price' => $offers], 'price')
            ->withMax(['listings as max_price' => $offers], 'price')
            ->withCount(['listings as pharmacies_count' => $offers])
            ->with('category');

        $origin = self::origin($filters);

        match ($filters['sort'] ?? 'relevance') {
            'price_asc' => $query->orderBy('min_price'),
            'price_desc' => $query->orderByDesc('min_price'),
            'pharmacies' => $query->orderByDesc('pharmacies_count'),
            default => $query->orderBy('generic_name'),
        };

        $page = $query->paginate($perPage)->withQueryString();

        // Attach the cheapest few offers to every medicine on this page in one query
        $listings = PharmacyMedicine::query()
            ->whereIn('medicine_id', $page->pluck('id'))
            ->where($offers)
            ->with('tenant')
            ->withAvailableQuantity()
            ->orderBy('price')
            ->get();

        // Near me: distance to each pharmacy, drop those outside the exact radius, nearest first
        if ($origin) {
            $listings = $listings
                ->each(fn ($l) => $l->tenant->setAttribute('distance_km', Geo::from($origin, $l->tenant)))
                ->filter(fn ($l) => $l->tenant->distance_km !== null && $l->tenant->distance_km <= $origin['radius'])
                ->sortBy(fn ($l) => [$l->tenant->distance_km, (float) $l->price]);
        }
        $listings = $listings->groupBy('medicine_id');

        $page->getCollection()->each(function (Medicine $m) use ($listings, $origin) {
            $offers = ($listings[$m->id] ?? collect())->values();
            $m->setRelation('offers', $offers->take(5)->values());
            if ($origin) {
                $m->setAttribute('nearest_km', $offers->first()?->tenant->distance_km);
            }
        });

        // "Nearest first" orders this page by the closest pharmacy stocking each medicine
        if ($origin && ($filters['sort'] ?? 'nearest') === 'nearest') {
            $page->setCollection($page->getCollection()->sortBy(fn ($m) => $m->nearest_km ?? INF)->values());
        }

        return $page;
    }

    /** ['lat', 'lng', 'radius'] when the filters carry a location, else null. */
    public static function origin(array $filters): ?array
    {
        if (! isset($filters['lat'], $filters['lng']) || $filters['lat'] === '' || $filters['lng'] === '') {
            return null;
        }

        return ['lat' => (float) $filters['lat'], 'lng' => (float) $filters['lng'], 'radius' => (float) ($filters['radius_km'] ?? 10)];
    }

    /**
     * Offers = public listings at active pharmacies matching location/price/stock filters.
     */
    public function offerConstraint(array $filters): \Closure
    {
        return function (Builder $q) use ($filters) {
            $q->where('is_public', true)
                ->whereHas('tenant', fn ($t) => $t->active()->pharmacies()
                    ->when($filters['city'] ?? null, fn ($t, $city) => $t->where('city', $city))
                    ->when($filters['sub_city'] ?? null, fn ($t, $sub) => $t->where('sub_city', $sub))
                    ->when(! empty($filters['open_24']), fn ($t) => $t->where('is_24_hours', true))
                    ->when(! empty($filters['delivery']), fn ($t) => $t->where('delivery_available', true))
                    ->when(self::origin($filters), fn ($t, $o) => Geo::withinBox($t, $o['lat'], $o['lng'], $o['radius'])))
                ->when($filters['min_price'] ?? null, fn ($q, $min) => $q->where('price', '>=', $min))
                ->when($filters['max_price'] ?? null, fn ($q, $max) => $q->where('price', '<=', $max))
                // Offers span every pharmacy: ignore the signed-in user's tenant scope on batches (hospital staff look up partner stock)
                ->when($filters['in_stock'] ?? true, fn ($q) => $q->whereHas('batches', fn ($b) => $b->withoutGlobalScope(TenantScope::class)->sellable()));
        };
    }

    /**
     * Pharmacies that currently stock every given medicine.
     */
    public function pharmaciesStockingAll(Collection $medicineIds, int $limit = 5, ?array $origin = null): Collection
    {
        $query = Tenant::pharmacies()->active();
        foreach ($medicineIds as $id) {
            $query->whereHas('pharmacyMedicines', fn ($l) => $l->where('medicine_id', $id)
                ->where('is_public', true)
                ->whereHas('batches', fn ($b) => $b->withoutGlobalScope(TenantScope::class)->sellable()));
        }

        if (! $origin) {
            return $query->limit($limit)->get();
        }

        // With a location: nearest first (pharmacies without a map pin go last)
        return $query->get()
            ->each(fn (Tenant $p) => $p->setAttribute('distance_km', Geo::from($origin, $p)))
            ->sortBy(fn (Tenant $p) => $p->distance_km ?? INF)
            ->take($limit)
            ->values();
    }
}
