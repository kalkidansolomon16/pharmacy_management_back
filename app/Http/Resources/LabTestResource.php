<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LabTestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'category' => $this->category,
            'specimen' => $this->specimen, 'unit' => $this->unit, 'result_type' => $this->result_type,
            'options' => $this->options, 'reference_low' => $this->reference_low, 'reference_high' => $this->reference_high,
            'critical_low' => $this->critical_low, 'critical_high' => $this->critical_high,
            'reference_text' => $this->reference_text, 'reference' => $this->referenceLabel(),
            'price' => (float) $this->price, 'turnaround_minutes' => $this->turnaround_minutes, 'is_active' => $this->is_active,
        ];
    }
}
