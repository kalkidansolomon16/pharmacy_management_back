<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'code' => $this->code, 'type' => $this->type,
            'tin_number' => $this->tin_number, 'phone' => $this->phone, 'email' => $this->email,
            'contact_person' => $this->contact_person, 'address' => $this->address,
            'default_copay_percent' => $this->default_copay_percent, 'credit_limit' => $this->credit_limit,
            'payment_terms_days' => $this->payment_terms_days, 'preauth_threshold' => $this->preauth_threshold,
            'coverage' => $this->coverage ?? array_fill_keys(\App\Models\Payer::COVERAGE_KEYS, true),
            'excluded_category_ids' => $this->excluded_category_ids ?? [], 'is_active' => $this->is_active,
            'memberships_count' => $this->whenCounted('memberships'),
            'outstanding' => $this->when(isset($this->outstanding_amount), fn () => round((float) $this->outstanding_amount, 2)),
        ];
    }
}
