<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'channel' => $this->channel,
            'status' => $this->status,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'fulfillment' => $this->fulfillment,
            'delivery_address' => $this->delivery_address,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'total_amount' => (float) $this->total_amount,
            'notes' => $this->notes,
            'cancel_reason' => $this->cancel_reason,
            'fulfilled_at' => $this->fulfilled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'allowed_transitions' => \App\Models\Order::TRANSITIONS[$this->status] ?? [],
            'items_count' => $this->whenCounted('items'),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'pharmacy' => new PublicPharmacyResource($this->whenLoaded('pharmacy')),
            'prescription' => $this->whenLoaded('prescription', fn () => $this->prescription ? [
                'id' => $this->prescription->id,
                'reference_code' => $this->prescription->reference_code,
                'status' => $this->prescription->status,
            ] : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'tax_total' => (float) $this->tax_total,
            'client_uuid' => $this->client_uuid,
            // Fiscal receipt data (Ministry of Revenue e-invoice)
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id, 'invoice_number' => $this->invoice->invoice_number,
                'tax_total' => (float) $this->invoice->tax_total, 'total' => (float) $this->invoice->total,
                'payer_share' => (float) $this->invoice->payer_share, 'patient_share' => (float) $this->invoice->patient_share,
                'fiscal' => ['status' => $this->invoice->fiscal_status, 'irn' => $this->invoice->irn, 'rrn' => $this->invoice->rrn, 'qr' => $this->invoice->fiscal_qr],
            ] : null),
        ];
    }
}
