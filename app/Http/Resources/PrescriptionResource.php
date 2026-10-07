<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionResource extends JsonResource
{
    /** Set when serving the public verification endpoint: hides clinical details. */
    public bool $publicView = false;

    public function asPublic(): static
    {
        $this->publicView = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $patient = $this->whenLoaded('patient');

        return [
            'id' => $this->id,
            'reference_code' => $this->reference_code,
            'status' => $this->isExpired() && in_array($this->status, ['active', 'partially_dispensed']) ? 'expired' : $this->status,
            'diagnosis' => $this->when(! $this->publicView, $this->diagnosis),
            'notes' => $this->when(! $this->publicView, $this->notes),
            'issued_at' => $this->issued_at,
            'expires_at' => $this->expires_at,
            'is_dispensable' => $this->isDispensable(),
            'patient' => $this->when($patient instanceof \App\Models\Patient, fn () => $this->publicView
                ? ['full_name' => $this->maskName($this->patient->full_name)]
                : new PatientResource($this->patient)),
            'doctor' => $this->whenLoaded('doctor', fn () => [
                'id' => $this->doctor->id,
                'name' => $this->doctor->name,
                'license_number' => $this->doctor->license_number,
            ]),
            'hospital' => $this->whenLoaded('hospital', fn () => [
                'id' => $this->hospital->id,
                'name' => $this->hospital->name,
                'city' => $this->hospital->city,
                'phone' => $this->hospital->phone,
            ]),
            'items' => PrescriptionItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at,
        ];
    }

    private function maskName(string $name): string
    {
        return collect(explode(' ', $name))
            ->map(fn ($part, $i) => $i === 0 ? $part : mb_substr($part, 0, 1).'.')
            ->implode(' ');
    }
}
