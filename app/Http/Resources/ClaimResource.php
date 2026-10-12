<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'claim_number' => $this->claim_number, 'status' => $this->status,
            'period_start' => $this->period_start->toDateString(), 'period_end' => $this->period_end->toDateString(),
            'invoices_count' => $this->invoices_count, 'total_amount' => (float) $this->total_amount,
            'paid_amount' => (float) $this->paid_amount, 'rejected_amount' => (float) $this->rejected_amount,
            'outstanding' => $this->outstanding(), 'notes' => $this->notes,
            'payer' => $this->whenLoaded('payer', fn () => ['id' => $this->payer->id, 'name' => $this->payer->name, 'type' => $this->payer->type]),
            'invoices' => InvoiceResource::collection($this->whenLoaded('invoices')),
            'submitted_at' => $this->submitted_at, 'paid_at' => $this->paid_at, 'created_at' => $this->created_at,
        ];
    }
}
