<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LabOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'priority' => $this->priority,
            'clinical_notes' => $this->clinical_notes,
            'visit_id' => $this->visit_id,
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'ordered_by' => $this->whenLoaded('orderer', fn () => $this->orderer?->name),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'test' => $i->relationLoaded('test') ? (new LabTestResource($i->test))->resolve() : null,
                'price' => (float) $i->price,
                'status' => $i->status,
                'result_value' => $i->result_value,
                'result_text' => $i->result_text,
                'unit' => $i->unit,
                'flag' => $i->flag,
                'reference' => $i->reference,
                'remarks' => $i->remarks,
                'resulted_at' => $i->resulted_at,
                'resulted_by' => $i->relationLoaded('resulter') ? $i->resulter?->name : null,
                'verified_at' => $i->verified_at,
                'verified_by' => $i->relationLoaded('verifier') ? $i->verifier?->name : null,
            ])),
            'items_count' => $this->whenCounted('items'),
            'payment' => $this->when(isset($this->payment_state), fn () => $this->payment_state),
            'created_at' => $this->created_at,
            'collected_at' => $this->collected_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
