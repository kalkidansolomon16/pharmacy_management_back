<?php

namespace App\Http\Controllers;

use App\Http\Resources\PublicFacilityResource;
use App\Models\Tenant;
use App\Services\Clinical\AppointmentService;
use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Public: which nearby hospitals have room at the time a patient wants, and each hospital's slots.
 */
class PublicAppointmentController extends Controller
{
    private const LEVEL_RANK = ['quiet' => 0, 'moderate' => 1, 'busy' => 2, 'full' => 3];

    public function __construct(private AppointmentService $appointments) {}

    /**
     * GET /public/hospitals/availability?lat=&lng=&date=2026-10-13&time=09:00
     * Hospitals near the patient, the free places at that time, the next free time and the live queue -
     * free hospitals first, then the least crowded, then the nearest.
     */
    public function nearby(Request $request)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.5', 'max:100'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'specialty' => ['nullable', 'string', 'max:110'], // only hospitals offering this specialty (slug)
        ]);
        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $radius = (float) ($data['radius_km'] ?? 15);
        $at = Carbon::parse($data['date'].' '.$data['time']);

        $hospitals = Geo::withinBox(Tenant::active()->where('type', Tenant::TYPE_HOSPITAL), $lat, $lng, $radius)
            ->when($data['specialty'] ?? null, fn ($q, $slug) => $q->whereHas('specialties', fn ($s) => $s->where('slug', $slug)->where('is_active', true)))
            ->with('specialties')
            ->get()
            ->filter(fn (Tenant $t) => $this->appointments->isBookable($t))
            ->each(fn (Tenant $t) => $t->setAttribute('distance_km', round(Geo::distanceKm($lat, $lng, (float) $t->latitude, (float) $t->longitude), 2)))
            ->filter(fn (Tenant $t) => $t->distance_km <= $radius)
            ->map(function (Tenant $t) use ($at) {
                $slot = $this->appointments->slotAt($t, $at);

                return [
                    'hospital' => (new PublicFacilityResource($t))->resolve(),
                    'slot' => $slot,                                   // the wanted time (null = closed then)
                    'open_at_time' => $slot !== null,
                    'available_at_time' => (bool) ($slot['available'] ?? false),
                    'next_free' => $this->appointments->nextFree($t, $at),
                    'live_queue' => $at->isToday() ? $this->appointments->liveQueue($t) : null,
                    'hours' => ['open' => $t->setting('appointment_open'), 'close' => $t->setting('appointment_close'), 'days' => $t->setting('appointment_days')],
                ];
            })
            ->sortBy(fn ($h) => [
                $h['available_at_time'] ? 0 : 1,
                self::LEVEL_RANK[$h['slot']['level'] ?? 'full'],
                $h['hospital']['distance_km'],
            ])
            ->values();

        return $this->respond($hospitals);
    }

    /** GET /public/hospitals/{id}/slots?date=2026-10-13 - every slot of a day, and the live queue. */
    public function slots(Request $request, int $id)
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']]);
        $hospital = Tenant::active()->where('type', Tenant::TYPE_HOSPITAL)->with('specialties')->findOrFail($id);
        abort_unless($this->appointments->isBookable($hospital), 404, 'This hospital does not take online appointments.');
        $date = Carbon::parse($data['date']);

        return $this->respond([
            'hospital' => (new PublicFacilityResource($hospital))->resolve(),
            'date' => $date->toDateString(),
            'closed' => $this->appointments->slots($hospital, $date) === [],
            'slots' => $this->appointments->slots($hospital, $date),
            'live_queue' => $date->isToday() ? $this->appointments->liveQueue($hospital) : null,
            'hours' => ['open' => $hospital->setting('appointment_open'), 'close' => $hospital->setting('appointment_close'), 'days' => $hospital->setting('appointment_days')],
        ]);
    }
}
