<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            // Local hospital time (Addis Ababa), so apps show exactly the booked time
            'date' => $this->scheduled_at->toDateString(),
            'time' => $this->scheduled_at->format('H:i'),
            'scheduled_at' => $this->scheduled_at->format('Y-m-d H:i'),
            'type' => $this->type,
            'specialty' => $this->whenLoaded('specialty', fn () => $this->specialty ? ['id' => $this->specialty->id, 'name' => $this->specialty->name] : null),
            'patient_name' => $this->patient_name,
            'patient_phone' => $this->patient_phone,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'reason' => $this->reason,
            'status' => $this->status,
            'cancel_reason' => $this->cancel_reason,
            'cancelled_by' => $this->cancelled_by,
            'checked_in_at' => $this->checked_in_at,
            'visit_id' => $this->visit_id,
            'patient_id' => $this->patient_id,
            'can_cancel' => $this->isUpcoming(),
            'hospital' => $this->whenLoaded('hospital', fn () => [
                'id' => $this->hospital->id,
                'name' => $this->hospital->name,
                'slug' => $this->hospital->slug,
                'phone' => $this->hospital->phone,
                'address_line' => $this->hospital->address_line,
                'city' => $this->hospital->city,
                'latitude' => $this->hospital->latitude,
                'longitude' => $this->hospital->longitude,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
