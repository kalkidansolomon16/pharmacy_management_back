<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * A medicine in public search results, with the pharmacies offering it.
 */
class SearchResultResource extends MedicineResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'min_price' => $this->min_price !== null ? (float) $this->min_price : null,
            'max_price' => $this->max_price !== null ? (float) $this->max_price : null,
            'pharmacies_count' => (int) $this->pharmacies_count,
            'offers' => $this->whenLoaded('offers', fn () => $this->offers->map(fn ($listing) => [
                'pharmacy_medicine_id' => $listing->id,
                'price' => (float) $listing->price,
                'available_quantity' => (int) $listing->available_quantity,
                'pharmacy' => (new PublicPharmacyResource($listing->tenant))->resolve(),
            ])),
        ];
    }
}
