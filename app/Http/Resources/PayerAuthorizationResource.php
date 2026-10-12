<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayerAuthorizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'amount' => (float) $this->amount, 'status' => $this->status,
            'reference_number' => $this->reference_number, 'notes' => $this->notes,
            'payer' => $this->whenLoaded('payer', fn () => ['id' => $this->payer->id, 'name' => $this->payer->name]),
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id' => $this->invoice->id, 'invoice_number' => $this->invoice->invoice_number,
                'customer_name' => $this->invoice->customer_name, 'total' => (float) $this->invoice->total,
            ]),
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'decided_at' => $this->decided_at, 'created_at' => $this->created_at,
        ];
    }
}
