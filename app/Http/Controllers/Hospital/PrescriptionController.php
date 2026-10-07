<?php

namespace App\Http\Controllers\Hospital;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hospital\StorePrescriptionRequest;
use App\Http\Resources\PrescriptionResource;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Prescriptions\PrescriptionService;
use Illuminate\Http\Request;

class PrescriptionController extends Controller
{
    public function __construct(private PrescriptionService $prescriptions) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Prescription::class);
        $user = $request->user();

        $prescriptions = Prescription::query()
            ->with('patient', 'doctor:id,name')
            ->withCount('items')
            // Doctors see their own prescriptions by default; hospital admins see everyone's
            ->when($user->hasRole(User::ROLE_DOCTOR) && ! $request->boolean('all'), fn ($q) => $q->where('doctor_id', $user->id))
            ->when($request->query('doctor_id'), fn ($q, $id) => $q->where('doctor_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('reference_code', 'like', "%{$term}%")
                ->orWhereHas('patient', fn ($p) => $p->where('full_name', 'like', "%{$term}%")->orWhere('mrn', 'like', "%{$term}%"))))
            ->latest('issued_at')
            ->paginate($this->perPage());

        return PrescriptionResource::collection($prescriptions);
    }

    public function store(StorePrescriptionRequest $request)
    {
        $this->authorize('create', Prescription::class);

        $patient = Patient::findOrFail($request->input('patient_id'));
        $prescription = $this->prescriptions->issue($request->user(), $patient, $request->validated());

        return $this->respond(
            new PrescriptionResource($prescription->load('hospital')),
            "Prescription {$prescription->reference_code} issued. Share the code with the patient.",
            201
        );
    }

    public function show(Prescription $prescription)
    {
        $this->authorize('view', $prescription);

        return new PrescriptionResource($prescription->load('items.medicine', 'patient', 'doctor', 'hospital'));
    }

    public function cancel(Request $request, Prescription $prescription)
    {
        $this->authorize('cancel', $prescription);
        $data = $request->validate(['reason' => ['required', 'string', 'max:191']]);

        return $this->respond(new PrescriptionResource($this->prescriptions->cancel($prescription, $data['reason'])), 'Prescription cancelled.');
    }
}
