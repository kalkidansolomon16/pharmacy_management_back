<?php

namespace App\Services\Catalog;

use App\Models\Medicine;
use App\Models\PharmacyMedicine;
use App\Models\Tenant;
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
            ->search($filters['q'] ?? null)
            ->when($filters['category'] ?? null, fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when(isset($filters['rx']), fn ($q) => $q->where('prescription_required', (bool) $filters['rx']))
            ->whereHas('listings', $offers)
            ->withMin(['listings as min_price' => $offers], 'price')
            ->withMax(['listings as max_price' => $offers], 'price')
            ->withCount(['listings as pharmacies_count' => $offers])
            ->with('category');

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
            ->get()
            ->groupBy('medicine_id');

        $page->getCollection()->each(fn (Medicine $m) => $m->setRelation('offers', ($listings[$m->id] ?? collect())->take(5)->values()));

        return $page;
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
                    ->when(! empty($filters['delivery']), fn ($t) => $t->where('delivery_available', true)))
                ->when($filters['min_price'] ?? null, fn ($q, $min) => $q->where('price', '>=', $min))
                ->when($filters['max_price'] ?? null, fn ($q, $max) => $q->where('price', '<=', $max))
                ->when($filters['in_stock'] ?? true, fn ($q) => $q->whereHas('batches', fn ($b) => $b->sellable()));
        };
    }

    /**
     * Pharmacies that currently stock every given medicine.
     */
    public function pharmaciesStockingAll(Collection $medicineIds, int $limit = 5): Collection
    {
        $query = Tenant::pharmacies()->active();
        foreach ($medicineIds as $id) {
            $query->whereHas('pharmacyMedicines', fn ($l) => $l->where('medicine_id', $id)
                ->where('is_public', true)
                ->whereHas('batches', fn ($b) => $b->sellable()));
        }

        return $query->limit($limit)->get();
    }
}
