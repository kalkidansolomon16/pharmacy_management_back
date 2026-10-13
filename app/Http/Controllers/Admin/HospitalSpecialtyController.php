<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\HospitalSpecialtyResource;
use App\Models\HospitalSpecialty;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform team: the list of hospital specialties every hospital / clinic chooses from.
 */
class HospitalSpecialtyController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    public function index()
    {
        return HospitalSpecialtyResource::collection(HospitalSpecialty::withCount('hospitals')->ordered()->get());
    }

    public function store(Request $request)
    {
        $specialty = HospitalSpecialty::create($this->validated($request));
        $this->logger->log('specialty_created', $specialty, [], "Hospital specialty “{$specialty->name}” added");

        return $this->respond(new HospitalSpecialtyResource($specialty->loadCount('hospitals')), 'Specialty added. Hospitals can now choose it.', 201);
    }

    public function update(Request $request, HospitalSpecialty $specialty)
    {
        $specialty->update($this->validated($request, $specialty));

        return $this->respond(new HospitalSpecialtyResource($specialty->loadCount('hospitals')), 'Specialty saved.');
    }

    public function destroy(HospitalSpecialty $specialty)
    {
        $count = $specialty->hospitals()->count();
        abort_if($count > 0, 422, "{$count} hospital(s) offer “{$specialty->name}”. Switch it off instead, so they keep their history.");
        $specialty->delete();

        return $this->respond(null, 'Specialty deleted.');
    }

    private function validated(Request $request, ?HospitalSpecialty $specialty = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('hospital_specialties', 'name')->ignore($specialty?->id)],
            'name_am' => ['nullable', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,1000'],
        ], ['name.unique' => 'There is already a specialty with this name.']);
    }
}
