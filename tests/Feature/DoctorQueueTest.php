<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Visit;
use Tests\TestCase;

/** Doctors see their own patients and unassigned ones; reception and the hospital admin see everyone. */
class DoctorQueueTest extends TestCase
{
    public function test_a_doctor_sees_only_their_own_and_unassigned_patients(): void
    {
        $hospital = $this->tenant('hospital');
        $hana = $this->userWithRole(User::ROLE_DOCTOR, $hospital, ['name' => 'Dr. Hana']);
        $dawit = $this->userWithRole(User::ROLE_DOCTOR, $hospital, ['name' => 'Dr. Dawit']);
        $visit = fn (?User $doctor, string $no) => Visit::withoutGlobalScopes()->create([
            'tenant_id' => $hospital->id, 'patient_id' => $this->patient($hospital)->id, 'visit_number' => $no, 'queue_number' => (int) substr($no, -1),
            'type' => 'opd', 'status' => 'waiting', 'priority' => 'normal', 'doctor_id' => $doctor?->id, 'checked_in_at' => now(),
        ]);
        $mine = $visit($hana, 'V-1');
        $open = $visit(null, 'V-2');
        $theirs = $visit($dawit, 'V-3');

        $list = $this->actingAs($hana)->getJson('/api/visits')->assertOk()
            ->assertJsonPath('meta.only_mine', true)->assertJsonPath('meta.counts.waiting', 2)->json('data');
        $this->assertEqualsCanonicalizing([$mine->id, $open->id], array_column($list, 'id'));

        // Another doctor's patient cannot be taken over from the queue
        $this->actingAs($hana)->postJson("/api/visits/{$theirs->id}/start")->assertForbidden();
        $this->actingAs($hana)->postJson("/api/visits/{$open->id}/start")->assertOk();
        // Once Dr. Hana takes the unassigned patient, Dr. Dawit no longer sees them
        $this->assertSame([$theirs->id], array_column($this->actingAs($dawit)->getJson('/api/visits')->json('data'), 'id'));

        // Reception and the hospital admin still see the whole clinic
        $this->actingAs($this->userWithRole(User::ROLE_RECEPTIONIST, $hospital))->getJson('/api/visits')->assertJsonCount(3, 'data')->assertJsonPath('meta.only_mine', false);
        $this->actingAs($this->userWithRole(User::ROLE_HOSPITAL_ADMIN, $hospital))->getJson('/api/visits')->assertJsonCount(3, 'data');
    }

    public function test_the_patients_page_follows_the_same_rule(): void
    {
        $hospital = $this->tenant('hospital');
        $hana = $this->userWithRole(User::ROLE_DOCTOR, $hospital);
        $dawit = $this->userWithRole(User::ROLE_DOCTOR, $hospital);
        $seen = fn (?User $doctor, string $status, string $no) => Visit::withoutGlobalScopes()->create([
            'tenant_id' => $hospital->id, 'patient_id' => ($p = $this->patient($hospital))->id, 'visit_number' => $no, 'queue_number' => 1,
            'type' => 'opd', 'status' => $status, 'priority' => 'normal', 'doctor_id' => $doctor?->id, 'checked_in_at' => now(),
        ]) ? $p : null;
        $hanas = $seen($hana, 'completed', 'V-1');
        $waiting = $seen(null, 'waiting', 'V-2');
        $dawits = $seen($dawit, 'completed', 'V-3');
        $brandNew = $this->patient($hospital);

        $ids = array_column($this->actingAs($hana)->getJson('/api/patients')->assertOk()->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$hanas->id, $waiting->id, $brandNew->id], $ids);
        $this->assertNotContains($dawits->id, $ids);

        $this->actingAs($this->userWithRole(User::ROLE_RECEPTIONIST, $hospital))->getJson('/api/patients')->assertJsonCount(4, 'data');
    }
}
