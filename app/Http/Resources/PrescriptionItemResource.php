<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'medicine_id' => $this->medicine_id,
            'medicine' => new MedicineResource($this->whenLoaded('medicine')),
            'dosage' => $this->dosage,
            'frequency' => $this->frequency,
            'duration_days' => $this->duration_days,
            'total_quantity' => $this->total_quantity,
            'dispensed_quantity' => $this->dispensed_quantity,
            'remaining_quantity' => $this->remainingQuantity(),
            'instructions' => $this->instructions,
        ];
    }
}
