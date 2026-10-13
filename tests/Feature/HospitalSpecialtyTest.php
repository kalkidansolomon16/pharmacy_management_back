<?php

namespace Tests\Feature;

use App\Models\HospitalSpecialty;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Specialties are managed by the platform team, chosen by hospitals, and used by patients to find and book. */
class HospitalSpecialtyTest extends TestCase
{
    public function test_the_platform_team_manages_the_list(): void
    {
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        $spine = $this->actingAs($platform)->postJson('/api/admin/specialties', ['name' => 'Orthopedics & Spine', 'name_am' => 'የአጥንትና የአከርካሪ'])
            ->assertCreated()->assertJsonPath('data.slug', 'orthopedics-spine')->json('data');
        $this->actingAs($platform)->postJson('/api/admin/specialties', ['name' => 'Orthopedics & Spine'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($platform)->putJson("/api/admin/specialties/{$spine['id']}", ['name' => 'Orthopedics & Spine Surgery', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        // In use by a hospital: switch off, do not delete
        $this->tenant('hospital')->specialties()->attach($spine['id']);
        $this->actingAs($platform)->deleteJson("/api/admin/specialties/{$spine['id']}")->assertUnprocessable();

        // Hospitals and patients cannot change the list
        $admin = $this->userWithRole(User::ROLE_HOSPITAL_ADMIN, $this->tenant('hospital'));
        $this->actingAs($admin)->postJson('/api/admin/specialties', ['name' => 'Mine'])->assertForbidden();
    }

    public function test_a_hospital_chooses_its_specialties_and_patients_filter_by_them(): void
    {
        [$spine, $heart, $retired] = [
            HospitalSpecialty::create(['name' => 'Orthopedics & Spine']),
            HospitalSpecialty::create(['name' => 'Cardiology']),
            HospitalSpecialty::create(['name' => 'Old Specialty', 'is_active' => false]),
        ];
        $ortho = $this->tenant('hospital', ['name' => 'Bone Clinic', 'latitude' => 9.01, 'longitude' => 38.76]);
        $general = $this->tenant('hospital', ['name' => 'General Hospital', 'latitude' => 9.012, 'longitude' => 38.762]);
        $admin = $this->userWithRole(User::ROLE_HOSPITAL_ADMIN, $ortho);

        $this->actingAs($admin)->getJson('/api/organization/specialties')->assertOk()->assertJsonCount(2, 'data.available'); // inactive ones are not offered
        $this->actingAs($admin)->putJson('/api/organization/specialties', ['specialty_ids' => [$spine->id, $retired->id]])
            ->assertOk()->assertJsonCount(1, 'data'); // a switched-off one cannot be added
        $general->specialties()->attach($heart->id);

        $nearby = $this->getJson('/api/public/facilities/nearby?type=hospital&lat=9.01&lng=38.76&specialty=orthopedics-spine')->assertOk()->json('data');
        $this->assertSame(['Bone Clinic'], array_column($nearby, 'name'));
        $this->assertSame('Orthopedics & Spine', $nearby[0]['specialties'][0]['name']);

        $this->getJson('/api/public/specialties')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.hospitals_count', 1);

        // A pharmacy cannot choose specialties
        $this->actingAs($this->userWithRole(User::ROLE_PHARMACY_ADMIN, $this->tenant('pharmacy')))->getJson('/api/organization/specialties')->assertNotFound();
    }

    public function test_patients_find_and_book_by_specialty(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-12 07:00'));
        $spine = HospitalSpecialty::create(['name' => 'Orthopedics & Spine']);
        $heart = HospitalSpecialty::create(['name' => 'Cardiology']);
        $ortho = $this->tenant('hospital', ['name' => 'Bone Clinic', 'latitude' => 9.01, 'longitude' => 38.76]);
        $this->tenant('hospital', ['name' => 'Heart Centre', 'latitude' => 9.011, 'longitude' => 38.761])->specialties()->attach($heart->id);
        $ortho->specialties()->attach($spine->id);
        $patient = $this->userWithRole(User::ROLE_CUSTOMER);

        $list = $this->getJson('/api/public/hospitals/availability?lat=9.01&lng=38.76&date=2026-10-12&time=09:00&specialty=orthopedics-spine')->json('data');
        $this->assertSame(['Bone Clinic'], array_column(array_column($list, 'hospital'), 'name'));

        $booking = ['hospital_id' => $ortho->id, 'date' => '2026-10-12', 'time' => '09:00', 'reason' => 'Lower back pain', 'patient_name' => 'Abebe',
            'patient_phone' => '0911223344', 'gender' => 'male'];
        $this->actingAs($patient)->postJson('/api/my/appointments', $booking + ['specialty_id' => $heart->id])
            ->assertUnprocessable()->assertJsonValidationErrors('specialty_id'); // not offered there
        $this->actingAs($patient)->postJson('/api/my/appointments', $booking + ['specialty_id' => $spine->id])
            ->assertCreated()->assertJsonPath('data.specialty.name', 'Orthopedics & Spine');
    }

    public function test_a_hospital_chooses_its_specialties_while_registering(): void
    {
        $spine = HospitalSpecialty::create(['name' => 'Orthopedics & Spine']);
        $off = HospitalSpecialty::create(['name' => 'Switched Off', 'is_active' => false]);
        $form = fn (string $type, array $org = []) => [
            'organization' => $org + ['type' => $type, 'name' => ucfirst($type).' '.uniqid(), 'license_number' => 'MOH/1/2018', 'phone' => '0115512345',
                'region' => 'Addis Ababa', 'city' => 'Addis Ababa'],
            'admin' => ['name' => 'Admin', 'email' => uniqid().'@test.et', 'phone' => '09'.random_int(10000000, 99999999), 'password' => 'Secret123', 'password_confirmation' => 'Secret123'],
        ];

        // Optional: a new clinic without specialists yet can add them later on the Organization page
        $this->postJson('/api/auth/register-organization', $form('hospital'))->assertCreated();
        // Only specialties from the active list can be chosen
        $this->postJson('/api/auth/register-organization', $form('hospital', ['specialty_ids' => [$off->id]]))->assertUnprocessable();

        $user = $this->postJson('/api/auth/register-organization', $form('hospital', ['specialty_ids' => [$spine->id]]))->assertCreated()->json('data.user');
        $this->assertSame(['Orthopedics & Spine'], \App\Models\Tenant::find($user['tenant_id'])->specialties->pluck('name')->all());

        // Pharmacies do not need any
        $this->postJson('/api/auth/register-organization', $form('pharmacy'))->assertCreated();
    }
}
