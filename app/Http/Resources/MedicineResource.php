<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'generic_name' => $this->generic_name,
            'brand_name' => $this->brand_name,
            'display_name' => $this->displayName(),
            'manufacturer' => $this->manufacturer,
            'dosage_form' => $this->dosage_form,
            'strength' => $this->strength,
            'unit' => $this->unit,
            'barcode' => $this->barcode,
            'prescription_required' => $this->prescription_required,
            'is_controlled' => $this->is_controlled,
            'storage_conditions' => $this->storage_conditions,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'category_id' => $this->category_id,
            'category' => new MedicineCategoryResource($this->whenLoaded('category')),
            'listings_count' => $this->whenCounted('listings'),
        ];
    }
}
