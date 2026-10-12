<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'type' => $this->type, 'tin_number' => $this->tin_number,
            'license_number' => $this->license_number, 'phone' => $this->phone, 'email' => $this->email,
            'contact_person' => $this->contact_person, 'address' => $this->address,
            'payment_terms_days' => $this->payment_terms_days, 'is_active' => $this->is_active,
            'purchase_orders_count' => $this->whenCounted('purchaseOrders'),
        ];
    }
}
