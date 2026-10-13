<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $clinical = $request->user()?->can('viewClinical', $this->resource) ?? false;

        return [
            'id' => $this->id,
            'visit_number' => $this->visit_number,
            'queue_number' => $this->queue_number,
            'type' => $this->type,
            'department' => $this->department,
            'status' => $this->status,
            'priority' => $this->priority,
            'chief_complaint' => $this->chief_complaint,
            'vitals' => $this->vitals,
            'diagnoses' => $this->diagnoses ?? [],
            // Encrypted clinical narrative: only for clinicians
            'history' => $this->when($clinical, fn () => $this->history),
            'examination' => $this->when($clinical, fn () => $this->examination),
            'plan' => $this->when($clinical, fn () => $this->plan),
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'doctor' => $this->whenLoaded('doctor', fn () => $this->doctor ? ['id' => $this->doctor->id, 'name' => $this->doctor->name] : null),
            'membership' => new PayerMembershipResource($this->whenLoaded('membership')),
            'lab_orders' => LabOrderResource::collection($this->whenLoaded('labOrders')),
            'prescriptions' => PrescriptionResource::collection($this->whenLoaded('prescriptions')),
            'invoices' => InvoiceResource::collection($this->whenLoaded('invoices')),
            // Card / consultation fee still owed before the doctor can see the patient (only when the hospital asks for it)
            'fees_due' => $this->seen_at || ! in_array($this->status, \App\Models\Visit::OPEN_STATUSES, true)
                ? null : app(\App\Services\Clinical\ClinicalService::class)->unpaidCheckInFees($this->resource),
            'checked_in_at' => $this->checked_in_at,
            'triaged_at' => $this->triaged_at,
            'seen_at' => $this->seen_at,
            'completed_at' => $this->completed_at,
            'waiting_minutes' => $this->seen_at ? null : (int) $this->checked_in_at->diffInMinutes(now()),
        ];
    }
}
