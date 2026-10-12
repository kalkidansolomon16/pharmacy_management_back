<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayerMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'payer_id' => $this->payer_id, 'patient_id' => $this->patient_id,
            'member_number' => $this->member_number, 'beneficiary_name' => $this->beneficiary_name, 'phone' => $this->phone,
            'scheme' => $this->scheme, 'employer' => $this->employer,
            'valid_from' => $this->valid_from?->toDateString(), 'valid_to' => $this->valid_to?->toDateString(),
            'copay_percent' => $this->copay_percent, 'effective_copay_percent' => $this->relationLoaded('payer') ? $this->copayPercent() : null,
            'status' => $this->status, 'is_valid' => $this->relationLoaded('payer') ? $this->isValid() : null,
            'payer' => $this->whenLoaded('payer', fn () => ['id' => $this->payer->id, 'name' => $this->payer->name, 'type' => $this->payer->type]),
        ];
    }
}
