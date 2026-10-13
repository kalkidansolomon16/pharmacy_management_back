<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\User;
use App\Models\Visit;
use App\Notifications\AppointmentBooked;
use App\Notifications\AppointmentCancelled;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Patients see how crowded nearby hospitals are, book a free time, and reception checks them in. */
class AppointmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A Monday morning in Addis Ababa
        $this->travelTo(Carbon::parse('2026-10-12 07:00'));
        Notification::fake();
    }

    private function hospital(array $settings = [], array $attributes = [])
    {
        return $this->tenant('hospital', $attributes + [
            'latitude' => 9.01, 'longitude' => 38.76,
            'settings' => $settings + ['appointments_per_slot' => 2, 'appointment_slot_minutes' => 30, 'appointment_open' => '08:00', 'appointment_close' => '10:00', 'appointment_days' => [1, 2, 3, 4, 5]],
        ]);
    }

    private function booking(int $hospitalId, string $time = '08:30', string $date = '2026-10-12'): array
    {
        return ['hospital_id' => $hospitalId, 'date' => $date, 'time' => $time, 'reason' => 'Cough and fever for 3 days',
            'patient_name' => 'Abebe Kebede', 'patient_phone' => '0911223344', 'gender' => 'male', 'type' => 'opd'];
    }

    public function test_a_patient_books_a_free_slot_and_the_hospital_is_told(): void
    {
        $hospital = $this->hospital();
        $reception = $this->userWithRole(User::ROLE_RECEPTIONIST, $hospital);
        $patient = $this->userWithRole(User::ROLE_CUSTOMER);

        $slots = $this->getJson("/api/public/hospitals/{$hospital->id}/slots?date=2026-10-12")->assertOk()->json('data.slots');
        $this->assertSame(['08:00', '08:30', '09:00', '09:30'], array_column($slots, 'time'));
        $this->assertSame('quiet', $slots[1]['level']);

        $this->actingAs($patient)->postJson('/api/my/appointments', $this->booking($hospital->id))
            ->assertCreated()->assertJsonPath('data.time', '08:30')->assertJsonPath('data.status', 'booked');

        Notification::assertSentTo($reception, AppointmentBooked::class);
        $this->getJson("/api/public/hospitals/{$hospital->id}/slots?date=2026-10-12")->assertJsonPath('data.slots.1.booked', 1)->assertJsonPath('data.slots.1.free', 1);
        $this->actingAs($patient)->getJson('/api/my/appointments')->assertJsonCount(1, 'data')->assertJsonPath('data.0.hospital.name', $hospital->name);
    }

    public function test_full_closed_and_past_times_cannot_be_booked(): void
    {
        $hospital = $this->hospital();
        foreach (range(1, 2) as $i) {
            $this->actingAs($this->userWithRole(User::ROLE_CUSTOMER))->postJson('/api/my/appointments', $this->booking($hospital->id))->assertCreated();
        }
        $late = $this->userWithRole(User::ROLE_CUSTOMER);

        $this->actingAs($late)->postJson('/api/my/appointments', $this->booking($hospital->id))->assertUnprocessable()->assertJsonValidationErrors('time');           // full
        $this->actingAs($late)->postJson('/api/my/appointments', $this->booking($hospital->id, '08:15'))->assertUnprocessable()->assertJsonValidationErrors('time');  // not a slot start
        $this->actingAs($late)->postJson('/api/my/appointments', $this->booking($hospital->id, '12:00'))->assertUnprocessable()->assertJsonValidationErrors('time');  // after closing
        $this->actingAs($late)->postJson('/api/my/appointments', $this->booking($hospital->id, '09:00', '2026-10-18'))->assertUnprocessable(); // Sunday: closed
        $this->actingAs($late)->postJson('/api/my/appointments', $this->booking($hospital->id, '06:00'))->assertUnprocessable();               // already past
        $this->actingAs($late)->postJson('/api/my/appointments', $this->booking($hospital->id, '09:00'))->assertCreated();                     // free

        // The same patient cannot hold two appointments at one hospital on one day
        $this->actingAs($late)->postJson('/api/my/appointments', $this->booking($hospital->id, '09:30'))->assertUnprocessable()->assertJsonValidationErrors('date');
    }

    public function test_nearby_hospitals_are_ranked_free_first_then_least_crowded(): void
    {
        $busy = $this->hospital([], ['name' => 'Busy Hospital', 'latitude' => 9.011, 'longitude' => 38.761]);   // nearest
        $quiet = $this->hospital([], ['name' => 'Quiet Clinic', 'latitude' => 9.02, 'longitude' => 38.77]);
        $this->hospital(['appointments_enabled' => false], ['name' => 'No Booking Hospital']);
        foreach (range(1, 2) as $i) {
            $this->actingAs($this->userWithRole(User::ROLE_CUSTOMER))->postJson('/api/my/appointments', $this->booking($busy->id))->assertCreated();
        }

        $list = $this->getJson('/api/public/hospitals/availability?lat=9.01&lng=38.76&date=2026-10-12&time=08:30')->assertOk()->json('data');

        $this->assertSame(['Quiet Clinic', 'Busy Hospital'], array_column(array_column($list, 'hospital'), 'name'));
        $this->assertTrue($list[0]['available_at_time']);
        $this->assertSame('full', $list[1]['slot']['level']);
        $this->assertSame('09:00', $list[1]['next_free']['time']); // where the busy one has room
        $this->assertSame(0, $list[0]['live_queue']['waiting']);
    }

    public function test_reception_checks_the_patient_in_to_the_queue(): void
    {
        $hospital = $this->hospital();
        $reception = $this->userWithRole(User::ROLE_RECEPTIONIST, $hospital);
        $patient = $this->userWithRole(User::ROLE_CUSTOMER);
        $id = $this->actingAs($patient)->postJson('/api/my/appointments', $this->booking($hospital->id))->json('data.id');

        $this->actingAs($reception)->getJson('/api/appointments?date=2026-10-12')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.counts.booked', 1);
        $this->actingAs($reception)->postJson("/api/appointments/{$id}/check-in")->assertOk()->assertJsonPath('data.status', 'waiting');

        $appointment = Appointment::acrossTenants()->find($id);
        $this->assertSame('checked_in', $appointment->status);
        $this->assertSame('Cough and fever for 3 days', Visit::acrossTenants()->find($appointment->visit_id)->chief_complaint);
        $this->assertSame('+251911223344', $appointment->patient->phone); // registered as a patient at the hospital

        // Another hospital's staff cannot see or touch it
        $other = $this->userWithRole(User::ROLE_RECEPTIONIST, $this->hospital());
        $this->actingAs($other)->postJson("/api/appointments/{$id}/no-show")->assertNotFound();
    }

    public function test_cancelling_by_the_patient_or_the_hospital(): void
    {
        $hospital = $this->hospital();
        $reception = $this->userWithRole(User::ROLE_RECEPTIONIST, $hospital);
        $patient = $this->userWithRole(User::ROLE_CUSTOMER);
        $first = $this->actingAs($patient)->postJson('/api/my/appointments', $this->booking($hospital->id))->json('data.id');
        $second = $this->actingAs($patient)->postJson('/api/my/appointments', $this->booking($hospital->id, '09:00', '2026-10-13'))->json('data.id');

        $this->actingAs($patient)->postJson("/api/my/appointments/{$first}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson("/api/public/hospitals/{$hospital->id}/slots?date=2026-10-12")->assertJsonPath('data.slots.1.booked', 0); // place freed

        $this->actingAs($reception)->postJson("/api/appointments/{$second}/cancel", ['reason' => 'Doctor on leave'])->assertOk();
        Notification::assertSentTo($patient, AppointmentCancelled::class, fn ($n, $channels) => in_array('mail', $channels, true));

        // Nobody else can cancel my appointment
        $stranger = $this->userWithRole(User::ROLE_CUSTOMER);
        $this->actingAs($stranger)->postJson("/api/my/appointments/{$first}/cancel")->assertNotFound();
    }

    public function test_the_hospital_sets_its_hours_and_capacity(): void
    {
        $hospital = $this->hospital();
        $admin = $this->userWithRole(User::ROLE_HOSPITAL_ADMIN, $hospital);

        $this->actingAs($admin)->putJson('/api/organization/settings', ['settings' => ['appointment_close' => '09:00', 'appointments_per_slot' => 5]])->assertOk();
        $slots = $this->getJson("/api/public/hospitals/{$hospital->id}/slots?date=2026-10-12")->json('data.slots');
        $this->assertSame(['08:00', '08:30'], array_column($slots, 'time'));
        $this->assertSame(5, $slots[0]['capacity']);

        // Closing before opening is refused; switching off hides the hospital from booking
        $this->actingAs($admin)->putJson('/api/organization/settings', ['settings' => ['appointment_close' => '07:00']])->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/organization/settings', ['settings' => ['appointments_enabled' => false]])->assertOk();
        $this->getJson("/api/public/hospitals/{$hospital->id}/slots?date=2026-10-12")->assertNotFound();
    }
}
