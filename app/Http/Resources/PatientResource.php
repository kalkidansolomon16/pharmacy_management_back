<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mrn' => $this->mrn,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'age' => $this->age(),
            'city' => $this->city,
            'address' => $this->address,
            'blood_group' => $this->blood_group,
            'allergies' => $this->allergies,
            'prescriptions_count' => $this->whenCounted('prescriptions'),
            'created_at' => $this->created_at,
        ];
    }
}
