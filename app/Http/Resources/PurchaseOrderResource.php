<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'po_number' => $this->po_number, 'status' => $this->status,
            'expected_at' => $this->expected_at?->toDateString(), 'total' => (float) $this->total, 'notes' => $this->notes,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'store' => new StoreResource($this->whenLoaded('store')),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'pharmacy_medicine_id' => $i->pharmacy_medicine_id,
                'medicine' => $i->listing?->medicine?->displayName(), 'unit' => $i->listing?->medicine?->unit,
                'gtin' => $i->listing?->medicine?->barcode,
                'quantity_ordered' => $i->quantity_ordered, 'quantity_received' => $i->quantity_received,
                'outstanding' => $i->outstanding(), 'unit_cost' => (float) $i->unit_cost,
                'line_total' => round($i->quantity_ordered * (float) $i->unit_cost, 2),
            ])),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'sent_at' => $this->sent_at, 'received_at' => $this->received_at, 'created_at' => $this->created_at,
        ];
    }
}
