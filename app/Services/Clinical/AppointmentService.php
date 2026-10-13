<?php

namespace App\Services\Clinical;

use App\Exceptions\BusinessRuleException;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visit;
use App\Notifications\AppointmentBooked;
use App\Notifications\AppointmentCancelled;
use App\Services\ActivityLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Online appointments: a hospital's time slots, how busy each one is, the live queue, and booking rules.
 */
class AppointmentService
{
    /** How far ahead patients can book. */
    public const MAX_DAYS_AHEAD = 30;

    /** How many upcoming appointments one patient may hold at once. */
    public const MAX_UPCOMING_PER_PATIENT = 3;

    /** Average minutes per consultation, for the estimated waiting time. */
    public const MINUTES_PER_PATIENT = 15;

    public function __construct(private ClinicalService $clinical, private ActivityLogger $logger) {}

    public function isBookable(Tenant $hospital): bool
    {
        return $hospital->type === Tenant::TYPE_HOSPITAL && $hospital->status === 'active' && (bool) $hospital->setting('appointments_enabled');
    }

    /**
     * "quiet" (plenty of room), "moderate", "busy" (few places left) or "full".
     */
    public static function level(int $booked, int $capacity): string
    {
        if ($capacity <= 0 || $booked >= $capacity) {
            return 'full';
        }
        $ratio = $booked / $capacity;

        return $ratio < 0.4 ? 'quiet' : ($ratio < 0.75 ? 'moderate' : 'busy');
    }

    /**
     * Every slot of one day: [{ time, start, booked, capacity, free, level, available }].
     * Empty when the hospital is closed that day.
     */
    public function slots(Tenant $hospital, Carbon $date): array
    {
        $date = $date->copy()->startOfDay();
        if (! in_array($date->isoWeekday(), array_map('intval', (array) $hospital->setting('appointment_days')), true)) {
            return [];
        }

        $minutes = (int) $hospital->setting('appointment_slot_minutes');
        $capacity = (int) $hospital->setting('appointments_per_slot');
        $open = Carbon::parse($date->toDateString().' '.$hospital->setting('appointment_open'));
        $close = Carbon::parse($date->toDateString().' '.$hospital->setting('appointment_close'));

        $booked = Appointment::acrossTenants()
            ->where('tenant_id', $hospital->id)
            ->whereBetween('scheduled_at', [$open, $close])
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->get(['scheduled_at'])
            ->countBy(fn ($a) => $a->scheduled_at->format('H:i'));

        $slots = [];
        for ($start = $open->copy(); $start->copy()->addMinutes($minutes)->lte($close); $start->addMinutes($minutes)) {
            $count = (int) ($booked[$start->format('H:i')] ?? 0);
            $past = $start->lte(now());
            $slots[] = [
                'time' => $start->format('H:i'),
                'start' => $start->format('Y-m-d H:i'),
                'booked' => $count,
                'capacity' => $capacity,
                'free' => max(0, $capacity - $count),
                'level' => self::level($count, $capacity),
                'available' => ! $past && $count < $capacity,
                'past' => $past,
            ];
        }

        return $slots;
    }

    /** The slot that contains a given time (or null when closed). */
    public function slotAt(Tenant $hospital, Carbon $at): ?array
    {
        foreach ($this->slots($hospital, $at) as $slot) {
            $start = Carbon::parse($slot['start']);
            if ($at->gte($start) && $at->lt($start->copy()->addMinutes((int) $hospital->setting('appointment_slot_minutes')))) {
                return $slot;
            }
        }

        return null;
    }

    /** The first bookable slot from a given time onwards, looking up to a week ahead. */
    public function nextFree(Tenant $hospital, Carbon $from): ?array
    {
        for ($day = 0; $day <= 7; $day++) {
            foreach ($this->slots($hospital, $from->copy()->addDays($day)) as $slot) {
                // On the first day, skip slots before the wanted time
                if ($slot['available'] && ($day > 0 || $slot['time'] >= $from->format('H:i'))) {
                    return $slot;
                }
            }
        }

        return null;
    }

    /**
     * Who is waiting right now (walk-ins and checked-in appointments), and roughly how long the wait is.
     */
    public function liveQueue(Tenant $hospital): array
    {
        $open = Visit::acrossTenants()->where('tenant_id', $hospital->id)->whereIn('status', Visit::OPEN_STATUSES)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $waiting = (int) ($open['waiting'] ?? 0);
        $doctors = max(1, User::where('tenant_id', $hospital->id)->where('status', 'active')->permission('consultations.manage')->count());
        $wait = (int) ceil($waiting / $doctors) * self::MINUTES_PER_PATIENT;

        return [
            'waiting' => $waiting,
            'in_consultation' => (int) ($open['in_consultation'] ?? 0),
            'doctors' => $doctors,
            'estimated_wait_minutes' => $wait,
            'level' => $wait <= 15 ? 'quiet' : ($wait <= 45 ? 'moderate' : ($wait <= 90 ? 'busy' : 'full')),
        ];
    }

    public function book(User $user, Tenant $hospital, array $data): Appointment
    {
        if (! $this->isBookable($hospital)) {
            throw BusinessRuleException::withErrors('hospital_id', "{$hospital->name} does not take online appointments.");
        }

        $at = Carbon::parse($data['date'].' '.$data['time']);
        if ($at->lte(now())) {
            throw BusinessRuleException::withErrors('time', 'Choose a time in the future.');
        }
        if ($at->gt(now()->addDays(self::MAX_DAYS_AHEAD)->endOfDay())) {
            throw BusinessRuleException::withErrors('date', 'Appointments can be booked up to '.self::MAX_DAYS_AHEAD.' days ahead.');
        }

        return DB::transaction(function () use ($user, $hospital, $data, $at) {
            // Lock the hospital row so two people cannot take the last place at the same moment
            Tenant::whereKey($hospital->id)->lockForUpdate()->first();

            $slot = collect($this->slots($hospital, $at))->firstWhere('time', $at->format('H:i'));
            if (! $slot) {
                throw BusinessRuleException::withErrors('time', "{$hospital->name} does not see patients at that time. Choose one of the offered times.");
            }
            if (! $slot['available']) {
                throw BusinessRuleException::withErrors('time', 'That time is fully booked. Please choose another time or hospital.');
            }

            $mine = Appointment::acrossTenants()->where('user_id', $user->id)->where('status', 'booked')->where('scheduled_at', '>', now());
            if ((clone $mine)->where('tenant_id', $hospital->id)->whereDate('scheduled_at', $at->toDateString())->exists()) {
                throw BusinessRuleException::withErrors('date', "You already have an appointment at {$hospital->name} that day.");
            }
            if ((clone $mine)->count() >= self::MAX_UPCOMING_PER_PATIENT) {
                throw BusinessRuleException::withErrors('date', 'You can hold up to '.self::MAX_UPCOMING_PER_PATIENT.' upcoming appointments. Cancel one first.');
            }

            $appointment = Appointment::create([
                'tenant_id' => $hospital->id,
                'user_id' => $user->id,
                'reference' => ReferenceGenerator::appointmentReference(),
                'scheduled_at' => $at,
                'type' => $data['type'] ?? 'opd',
                'specialty_id' => $data['specialty_id'] ?? null,
                'patient_name' => $data['patient_name'] ?? $user->name,
                'patient_phone' => ReferenceGenerator::normalizePhone($data['patient_phone'] ?? $user->phone),
                'gender' => $data['gender'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'reason' => $data['reason'],
                'status' => 'booked',
            ]);

            $this->logger->log('appointment_booked', $appointment, [], "{$appointment->patient_name} booked {$appointment->reference} at {$hospital->name} for {$at->format('d M H:i')}");

            User::where('tenant_id', $hospital->id)->where('status', 'active')->permission('visits.manage')->get()
                ->each->notify(new AppointmentBooked($appointment));

            return $appointment;
        });
    }

    public function cancel(Appointment $appointment, string $by, ?string $reason = null): Appointment
    {
        if ($appointment->status !== 'booked') {
            throw BusinessRuleException::withErrors('status', 'Only a booked appointment can be cancelled.');
        }
        $appointment->update(['status' => 'cancelled', 'cancelled_by' => $by, 'cancel_reason' => $reason]);

        if ($by === 'hospital' && $appointment->user) {
            $appointment->user->notify(new AppointmentCancelled($appointment));
        }

        return $appointment;
    }

    public function noShow(Appointment $appointment): Appointment
    {
        if ($appointment->status !== 'booked') {
            throw BusinessRuleException::withErrors('status', 'Only a booked appointment can be marked as no-show.');
        }
        $appointment->update(['status' => 'no_show']);

        return $appointment;
    }

    /**
     * The patient arrived: find (or register) them at this hospital and put them in today's queue.
     */
    public function checkIn(Appointment $appointment, ?int $doctorId = null): Visit
    {
        if ($appointment->status !== 'booked') {
            throw BusinessRuleException::withErrors('status', 'This appointment is not waiting for check-in.');
        }

        return DB::transaction(function () use ($appointment, $doctorId) {
            $patient = Patient::where('phone', $appointment->patient_phone)->first()
                ?? Patient::create([
                    'full_name' => $appointment->patient_name,
                    'phone' => $appointment->patient_phone,
                    'gender' => $appointment->gender ?: 'female',
                    'date_of_birth' => $appointment->date_of_birth,
                    'user_id' => $appointment->user_id,
                ]);

            $visit = $this->clinical->checkIn($patient, [
                'type' => $appointment->type,
                'chief_complaint' => $appointment->reason,
                'doctor_id' => $doctorId,
            ]);

            $appointment->update(['status' => 'checked_in', 'patient_id' => $patient->id, 'visit_id' => $visit->id, 'checked_in_at' => now()]);

            return $visit;
        });
    }
}
