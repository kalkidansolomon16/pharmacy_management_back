<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'transfer_number' => $this->transfer_number, 'status' => $this->status, 'notes' => $this->notes,
            'from_store' => new StoreResource($this->whenLoaded('fromStore')),
            'to_store' => new StoreResource($this->whenLoaded('toStore')),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'pharmacy_medicine_id' => $i->pharmacy_medicine_id,
                'medicine' => $i->listing?->medicine?->displayName(), 'quantity' => $i->quantity,
                'dispatched_quantity' => $i->dispatched_quantity, 'allocations' => $i->allocations ?? [],
            ])),
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'dispatched_at' => $this->dispatched_at, 'received_at' => $this->received_at, 'created_at' => $this->created_at,
        ];
    }
}
