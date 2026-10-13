<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * A pharmacy, hospital or clinic as patients see it on the public map: contact details and services only.
 */
class PublicFacilityResource extends PublicPharmacyResource
{
    public function toArray(Request $request): array
    {
        $modules = $this->enabledModules();

        return parent::toArray($request) + [
            'type' => $this->type, // pharmacy | hospital
            'services' => array_values(array_filter([
                $this->type === 'hospital' && in_array('clinical', $modules, true) ? 'opd' : null,
                $this->type === 'hospital' && $this->is_24_hours ? 'emergency' : null,
                $this->type === 'hospital' && in_array('lab', $modules, true) ? 'laboratory' : null,
                $this->type === 'hospital' && in_array('pharmacy', $modules, true) ? 'pharmacy' : null,
                in_array('insurance', $modules, true) ? 'insurance' : null,
            ])),
            // Specialties a hospital offers (Orthopedics & Spine, Cardiology...), active ones only
            'specialties' => $this->whenLoaded('specialties', fn () => $this->specialties->where('is_active', true)->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'name_am' => $s->name_am, 'slug' => $s->slug, 'icon' => $s->icon,
            ])->values()),
        ];
    }
}
