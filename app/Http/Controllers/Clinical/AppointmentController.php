<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\VisitResource;
use App\Models\Appointment;
use App\Models\User;
use App\Services\Clinical\AppointmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Reception: the day's online appointments, checking patients in (into the queue), no-shows and cancellations.
 */
class AppointmentController extends Controller
{
    public function __construct(private AppointmentService $appointments) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('visits.view'), 403);
        $date = Carbon::parse($request->query('date', today()->toDateString()));

        $items = Appointment::query()
            ->with('specialty')
            ->whereDate('scheduled_at', $date)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('patient_name', 'like', "%{$t}%")
                ->orWhere('patient_phone', 'like', "%{$t}%")->orWhere('reference', 'like', "%{$t}%")))
            ->orderBy('scheduled_at')
            ->get();

        $tenant = $request->user()->tenant;

        return AppointmentResource::collection($items)->additional(['meta' => [
            'date' => $date->toDateString(),
            'counts' => Appointment::whereDate('scheduled_at', $date)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'slots' => $this->appointments->slots($tenant, $date),
            'live_queue' => $date->isToday() ? $this->appointments->liveQueue($tenant) : null,
            'enabled' => (bool) $tenant->setting('appointments_enabled'),
        ]]);
    }

    public function checkIn(Request $request, Appointment $appointment)
    {
        abort_unless($request->user()->can('visits.manage'), 403);
        $data = $request->validate(['doctor_id' => ['nullable', 'integer']]);
        if (! empty($data['doctor_id'])) {
            User::where('tenant_id', $request->user()->tenant_id)->permission('consultations.manage')->findOrFail($data['doctor_id']);
        }

        $visit = $this->appointments->checkIn($appointment, $data['doctor_id'] ?? null);

        return $this->respond(new VisitResource($visit->load('patient')), "{$appointment->patient_name} is checked in - queue number {$visit->queue_number}.");
    }

    public function noShow(Request $request, Appointment $appointment)
    {
        abort_unless($request->user()->can('visits.manage'), 403);

        return $this->respond(new AppointmentResource($this->appointments->noShow($appointment)), 'Marked as no-show.');
    }

    public function cancel(Request $request, Appointment $appointment)
    {
        abort_unless($request->user()->can('visits.manage'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:191']]);

        return $this->respond(
            new AppointmentResource($this->appointments->cancel($appointment, 'hospital', $data['reason'])),
            'Appointment cancelled. The patient was notified.'
        );
    }
}
