<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pharmacy_medicine_id' => $this->pharmacy_medicine_id,
            'medicine_id' => $this->medicine_id,
            'medicine' => new MedicineResource($this->whenLoaded('medicine')),
            'quantity' => $this->quantity,
            'fulfilled_quantity' => $this->fulfilled_quantity,
            'unit_price' => (float) $this->unit_price,
            'line_total' => (float) $this->line_total,
            'prescription_item_id' => $this->prescription_item_id,
            'batches' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'batch_number' => $a->batch?->batch_number,
                'expiry_date' => $a->batch?->expiry_date?->toDateString(),
                'quantity' => $a->quantity,
            ])),
        ];
    }
}
