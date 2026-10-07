<?php

namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hospital\SavePatientRequest;
use App\Http\Resources\PatientResource;
use App\Http\Resources\PrescriptionResource;
use App\Models\Patient;
use App\Models\User;
use App\Support\ReferenceGenerator;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Patient::class);

        $patients = Patient::query()
            ->withCount('prescriptions')
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('full_name', 'like', "%{$term}%")
                ->orWhere('mrn', 'like', "%{$term}%")
                ->orWhere('phone', 'like', '%'.ltrim(preg_replace('/\D/', '', $term), '0').'%')))
            ->latest()
            ->paginate($this->perPage());

        return PatientResource::collection($patients);
    }

    public function store(SavePatientRequest $request)
    {
        $this->authorize('create', Patient::class);
        $patient = Patient::create($this->attributes($request->validated()));

        return $this->respond(new PatientResource($patient), "{$patient->full_name} registered.", 201);
    }

    public function show(Patient $patient)
    {
        $this->authorize('view', $patient);

        return $this->respond([
            'patient' => new PatientResource($patient),
            'prescriptions' => PrescriptionResource::collection(
                $patient->prescriptions()->with('doctor:id,name', 'items.medicine')->latest()->limit(20)->get()
            ),
        ]);
    }

    public function update(SavePatientRequest $request, Patient $patient)
    {
        $this->authorize('update', $patient);
        $patient->update($this->attributes($request->validated()));

        return $this->respond(new PatientResource($patient), 'Patient updated.');
    }

    /** Normalise the phone and link a customer account with the same number, so they get notified. */
    private function attributes(array $data): array
    {
        $data['phone'] = ReferenceGenerator::normalizePhone($data['phone']);
        $data['user_id'] = User::where('phone', $data['phone'])->role(User::ROLE_CUSTOMER)->value('id');

        return $data;
    }
}
