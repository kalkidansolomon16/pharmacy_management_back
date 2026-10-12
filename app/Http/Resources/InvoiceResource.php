<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'document_type' => $this->document_type,
            'status' => $this->status,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_tin' => $this->customer_tin,
            'patient_id' => $this->patient_id,
            'visit_id' => $this->visit_id,
            'order_id' => $this->order_id,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'tax_total' => (float) $this->tax_total,
            'total' => (float) $this->total,
            'payer_share' => (float) $this->payer_share,
            'patient_share' => (float) $this->patient_share,
            'amount_paid' => (float) $this->amount_paid,
            'payer_paid' => (float) $this->payer_paid,
            'payer_rejected' => (float) $this->payer_rejected,
            'patient_balance' => $this->patientBalance(),
            'payer_balance' => $this->payerBalance(),
            'payer' => $this->whenLoaded('payer', fn () => $this->payer ? ['id' => $this->payer->id, 'name' => $this->payer->name, 'type' => $this->payer->type] : null),
            'membership' => new PayerMembershipResource($this->whenLoaded('membership')),
            'claim' => $this->whenLoaded('claim', fn () => $this->claim ? ['id' => $this->claim->id, 'claim_number' => $this->claim->claim_number] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'item_type' => $i->item_type, 'description' => $i->description, 'quantity' => $i->quantity,
                'unit_price' => (float) $i->unit_price, 'tax_category' => $i->tax_category, 'tax_rate' => $i->tax_rate,
                'tax_amount' => (float) $i->tax_amount, 'line_total' => (float) $i->line_total, 'covered' => $i->covered,
                'payer_amount' => (float) $i->payer_amount, 'patient_amount' => (float) $i->patient_amount,
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id, 'amount' => (float) $p->amount, 'method' => $p->method, 'reference' => $p->reference,
                'paid_at' => $p->paid_at, 'received_by' => $p->receiver?->name,
            ])),
            'authorizations' => $this->whenLoaded('authorizations', fn () => PayerAuthorizationResource::collection($this->authorizations)->resolve()),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            // Ministry of Revenue e-invoicing
            'fiscal' => [
                'status' => $this->fiscal_status,
                'irn' => $this->irn,
                'rrn' => $this->rrn,
                'qr' => $this->fiscal_qr,
                'error' => $this->fiscal_error,
                'registered_at' => $this->fiscal_registered_at,
            ],
            'void_reason' => $this->void_reason,
            'issued_at' => $this->issued_at,
            'due_at' => $this->due_at?->toDateString(),
            'voided_at' => $this->voided_at,
        ];
    }
}
