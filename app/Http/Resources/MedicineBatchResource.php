<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicineBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $days = (int) today()->diffInDays($this->expiry_date, false);

        return [
            'id' => $this->id,
            'pharmacy_medicine_id' => $this->pharmacy_medicine_id,
            'batch_number' => $this->batch_number,
            'initial_quantity' => $this->initial_quantity,
            'quantity' => $this->quantity,
            'expiry_date' => $this->expiry_date->toDateString(),
            'days_to_expiry' => $days,
            'expiry_status' => match (true) {
                $days <= 0 => 'expired',
                $days <= 30 => 'critical',
                $days <= 90 => 'warning',
                default => 'ok',
            },
            'purchase_price' => (float) $this->purchase_price,
            'supplier' => $this->supplier,
            'received_at' => $this->received_at?->toDateString(),
            'listing' => new PharmacyMedicineResource($this->whenLoaded('pharmacyMedicine')),
            'created_at' => $this->created_at,
        ];
    }
}
