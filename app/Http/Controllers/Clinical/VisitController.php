<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\CheckInRequest;
use App\Http\Requests\Clinical\ConsultationRequest;
use App\Http\Requests\Clinical\TriageRequest;
use App\Http\Resources\VisitResource;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\Clinical\ClinicalService;
use Illuminate\Http\Request;

/**
 * Patient queue and encounters: reception checks patients in, nurses triage, doctors consult.
 */
class VisitController extends Controller
{
    public function __construct(private ClinicalService $clinical) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Visit::class);
        $user = $request->user();
        // Doctors only see their own patients and those not yet assigned to any doctor
        $onlyMine = self::seesOnlyOwnPatients($user) || $request->boolean('mine');
        $mine = fn ($q) => $q->where(fn ($w) => $w->where('doctor_id', $user->id)->orWhereNull('doctor_id'));

        $visits = Visit::query()
            ->with('patient', 'doctor:id,name', 'membership.payer')
            ->when($request->query('scope', 'today') === 'today', fn ($q) => $q->where(fn ($w) => $w
                ->whereDate('checked_in_at', today())->orWhereIn('status', Visit::OPEN_STATUSES)))
            ->when($request->query('status'), fn ($q, $s) => $s === 'open' ? $q->whereIn('status', Visit::OPEN_STATUSES) : $q->where('status', $s))
            ->when($onlyMine, $mine)
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w->where('visit_number', 'like', "%{$term}%")
                ->orWhereHas('patient', fn ($p) => $p->where('full_name', 'like', "%{$term}%")->orWhere('mrn', 'like', "%{$term}%"))))
            ->orderByRaw("case when status in ('completed','cancelled') then 1 else 0 end")
            ->orderByRaw("case when priority = 'urgent' then 0 else 1 end")
            ->orderBy('checked_in_at')
            ->paginate($this->perPage(30));

        $counts = Visit::whereDate('checked_in_at', today())->when($onlyMine, $mine)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return VisitResource::collection($visits)->additional(['meta' => ['counts' => $counts, 'only_mine' => self::seesOnlyOwnPatients($user)]]);
    }

    public function store(CheckInRequest $request)
    {
        $visit = $this->clinical->checkIn(Patient::findOrFail($request->input('patient_id')), $request->validated());

        return $this->respond(new VisitResource($visit), "{$visit->patient->full_name} checked in - queue number {$visit->queue_number}.", 201);
    }

    public function show(Visit $visit)
    {
        $this->authorize('view', $visit);

        return new VisitResource($visit->load(
            'patient.memberships.payer', 'doctor:id,name', 'membership.payer',
            'labOrders.items.test', 'labOrders.orderer:id,name',
            'prescriptions.items.medicine', 'prescriptions.doctor:id,name',
            'invoices.payer'
        ));
    }

    public function triage(TriageRequest $request, Visit $visit)
    {
        return $this->respond(new VisitResource($this->clinical->triage($visit, $request->validated())->load('patient')), 'Vital signs recorded.');
    }

    /** Doctors work from their own list; reception, nurses and the hospital admin see everyone. */
    public static function seesOnlyOwnPatients(User $user): bool
    {
        return $user->hasRole(User::ROLE_DOCTOR) && ! $user->hasAnyRole([User::ROLE_HOSPITAL_ADMIN, User::ROLE_OWNER]);
    }

    public function start(Request $request, Visit $visit)
    {
        $this->authorize('consult', $visit);
        $user = $request->user();
        if (self::seesOnlyOwnPatients($user) && $visit->doctor_id && $visit->doctor_id !== $user->id) {
            abort(403, 'This patient is assigned to '.($visit->doctor?->name ?? 'another doctor').'. Ask reception to reassign the patient to you.');
        }

        return $this->respond(new VisitResource($this->clinical->start($visit, $request->user())->load('patient')), 'Consultation started.');
    }

    public function notes(ConsultationRequest $request, Visit $visit)
    {
        return $this->respond(new VisitResource($this->clinical->saveNotes($visit, $request->validated())->load('patient')), 'Consultation notes saved.');
    }

    public function assign(Request $request, Visit $visit)
    {
        $this->authorize('update', $visit);
        $data = $request->validate(['doctor_id' => ['required', 'integer']]);
        $doctor = User::where('tenant_id', $visit->tenant_id)->permission('consultations.manage')->findOrFail($data['doctor_id']);
        $visit->update(['doctor_id' => $doctor->id]);

        return $this->respond(new VisitResource($visit->load('patient', 'doctor:id,name')), "Assigned to {$doctor->name}.");
    }

    public function complete(Request $request, Visit $visit)
    {
        $this->authorize('consult', $visit);

        return $this->respond(new VisitResource($this->clinical->complete($visit)->load('patient')), 'Visit completed.');
    }

    public function cancel(Request $request, Visit $visit)
    {
        $this->authorize('update', $visit);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:191']]);

        return $this->respond(new VisitResource($this->clinical->cancel($visit, $data['reason'] ?? null)->load('patient')), 'Visit cancelled.');
    }

    /** Doctors on duty (for assigning the queue). */
    public function doctors(Request $request)
    {
        $this->authorize('viewAny', Visit::class);

        return $this->respond(User::where('tenant_id', $request->user()->tenant_id)->where('status', 'active')
            ->permission('consultations.manage')->orderBy('name') // anyone who consults, whatever their role->get(['id', 'name', 'license_number'])
            ->map(fn ($d) => $d->toArray() + ['waiting' => Visit::where('doctor_id', $d->id)->whereIn('status', Visit::OPEN_STATUSES)->count()]));
    }

    /** ICD-10 diagnosis picker. */
    public function diagnoses(Request $request)
    {
        $term = strtolower((string) $request->query('q'));

        return $this->respond(collect(config('ethiopia.diagnoses'))
            ->filter(fn ($d) => $term === '' || str_contains(strtolower($d['code'].' '.$d['name']), $term))
            ->values()->take(20));
    }
}
