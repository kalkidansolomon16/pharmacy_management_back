<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\Visit;
use App\Rules\EthiopianPhone;
use App\Services\Clinical\AppointmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A patient's own hospital appointments.
 */
class AppointmentController extends Controller
{
    public function __construct(private AppointmentService $appointments) {}

    public function index(Request $request)
    {
        $upcoming = $request->query('status') !== 'past';
        $items = Appointment::acrossTenants()
            ->with('hospital', 'specialty')
            ->where('user_id', $request->user()->id)
            ->when($upcoming,
                fn ($q) => $q->where('status', 'booked')->where('scheduled_at', '>=', now()->subHours(2))->orderBy('scheduled_at'),
                fn ($q) => $q->where(fn ($w) => $w->where('status', '!=', 'booked')->orWhere('scheduled_at', '<', now()->subHours(2)))->orderByDesc('scheduled_at'))
            ->paginate($this->perPage(20));

        return AppointmentResource::collection($items);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('orders.place') || $request->user()->hasRole('customer'), 403, 'Appointments are booked with a patient account.');

        $data = $request->validate([
            'hospital_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'type' => ['nullable', Rule::in(array_diff(Visit::TYPES, ['emergency']))],
            'specialty_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'patient_name' => ['required', 'string', 'max:150'],
            'patient_phone' => ['required', new EthiopianPhone],
            'gender' => ['required', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
        ], [], ['reason' => 'reason for the visit', 'patient_name' => 'patient name', 'patient_phone' => 'phone']);

        $hospital = Tenant::active()->where('type', Tenant::TYPE_HOSPITAL)->find($data['hospital_id']);
        abort_unless($hospital, 422, 'This hospital is not available on MedLink.');
        if (! empty($data['specialty_id']) && ! $hospital->specialties()->where('hospital_specialties.id', $data['specialty_id'])->where('is_active', true)->exists()) {
            throw \App\Exceptions\BusinessRuleException::withErrors('specialty_id', "{$hospital->name} does not offer that specialty. Choose one of its specialties.");
        }

        $appointment = $this->appointments->book($request->user(), $hospital, $data);

        return $this->respond(
            new AppointmentResource($appointment->load('hospital', 'specialty')),
            "Booked! {$hospital->name}, {$appointment->scheduled_at->format('D d M')} at {$appointment->scheduled_at->format('H:i')}. Reference {$appointment->reference}.",
            201
        );
    }

    public function cancel(Request $request, int $id)
    {
        $appointment = Appointment::acrossTenants()->where('user_id', $request->user()->id)->findOrFail($id);
        abort_unless($appointment->isUpcoming(), 422, 'Only an upcoming appointment can be cancelled.');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:191']]);

        $this->appointments->cancel($appointment, 'patient', $data['reason'] ?? null);

        return $this->respond(new AppointmentResource($appointment->load('hospital')), 'Your appointment was cancelled.');
    }
}
