<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'return_number' => $this->return_number, 'quantity' => $this->quantity,
            'reason' => $this->reason, 'value' => (float) $this->value, 'status' => $this->status,
            'credit_note_number' => $this->credit_note_number, 'notes' => $this->notes,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier?->name),
            'batch' => $this->whenLoaded('batch', fn () => [
                'batch_number' => $this->batch->batch_number,
                'expiry_date' => $this->batch->expiry_date?->toDateString(),
                'medicine' => $this->batch->pharmacyMedicine?->medicine?->displayName(),
            ]),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at,
        ];
    }
}
