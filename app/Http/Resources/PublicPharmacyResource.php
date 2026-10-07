<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the public may see about a pharmacy (no licence / tax details).
 */
class PublicPharmacyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'phone' => $this->phone,
            'region' => $this->region,
            'city' => $this->city,
            'sub_city' => $this->sub_city,
            'woreda' => $this->woreda,
            'address_line' => $this->address_line,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'opening_hours' => $this->opening_hours,
            'is_24_hours' => $this->is_24_hours,
            'delivery_available' => $this->delivery_available,
            'description' => $this->description,
            'medicines_count' => $this->whenCounted('pharmacyMedicines'),
        ];
    }
}
