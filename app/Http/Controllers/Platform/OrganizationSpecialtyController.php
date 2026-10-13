<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\HospitalSpecialtyResource;
use App\Models\HospitalSpecialty;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * A hospital / clinic chooses which specialties it offers (shown to patients, used for filters and bookings).
 */
class OrganizationSpecialtyController extends Controller
{
    public function show(Request $request)
    {
        $tenant = $this->hospital($request);

        return $this->respond([
            'selected' => $tenant->specialties()->pluck('hospital_specialties.id'),
            'available' => HospitalSpecialtyResource::collection(HospitalSpecialty::active()->ordered()->get()),
        ]);
    }

    public function update(Request $request)
    {
        $tenant = $this->hospital($request);
        abort_unless($request->user()->can('organization.manage'), 403);
        $data = $request->validate([
            'specialty_ids' => ['present', 'array', 'max:40'],
            'specialty_ids.*' => ['integer', 'distinct'],
        ]);

        // Only active specialties can be chosen; switched-off ones a hospital already had are kept
        $active = HospitalSpecialty::active()->whereIn('id', $data['specialty_ids'])->pluck('id');
        $keptInactive = $tenant->specialties()->where('is_active', false)->pluck('hospital_specialties.id');
        $tenant->specialties()->sync($active->merge($keptInactive)->unique()->values());

        return $this->respond(
            HospitalSpecialtyResource::collection($tenant->specialties()->get()),
            'Specialties saved. Patients can now find you by them.'
        );
    }

    private function hospital(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant && $tenant->type === Tenant::TYPE_HOSPITAL, 404, 'Specialties are for hospitals and clinics.');

        return $tenant;
    }
}
