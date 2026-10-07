<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PharmacyMedicineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $available = $this->available_quantity !== null ? (int) $this->available_quantity : null;

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'medicine_id' => $this->medicine_id,
            'price' => (float) $this->price,
            'reorder_level' => $this->reorder_level,
            'is_public' => $this->is_public,
            'available_quantity' => $available,
            'stock_status' => $available === null ? null : match (true) {
                $available === 0 => 'out_of_stock',
                $available <= $this->reorder_level => 'low_stock',
                default => 'in_stock',
            },
            'nearest_expiry' => $this->nearest_expiry,
            'medicine' => new MedicineResource($this->whenLoaded('medicine')),
            'batches' => MedicineBatchResource::collection($this->whenLoaded('batches')),
            'pharmacy' => new PublicPharmacyResource($this->whenLoaded('tenant')),
            'updated_at' => $this->updated_at,
        ];
    }
}
