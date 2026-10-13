<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Patient;
use App\Models\User;
use Tests\TestCase;

/** The platform team sees every patient and which hospital they are registered at. */
class AdminPatientsTest extends TestCase
{
    public function test_the_platform_admin_sees_all_patients_with_their_hospital(): void
    {
        $entoto = $this->tenant('hospital', ['name' => 'Entoto Hospital']);
        $kirkos = $this->tenant('hospital', ['name' => 'Kirkos Clinic']);
        Patient::acrossTenants()->create(['tenant_id' => $entoto->id, 'full_name' => 'Abebe Kebede', 'phone' => '+251911223344', 'gender' => 'male', 'mrn' => 'E-1']);
        Patient::acrossTenants()->create(['tenant_id' => $kirkos->id, 'full_name' => 'Meron Alemu', 'phone' => '+251922334455', 'gender' => 'female', 'mrn' => 'K-1']);
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        $list = $this->actingAs($platform)->getJson('/api/admin/patients')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.totals.patients', 2)->json('data');
        $this->assertEqualsCanonicalizing(['Entoto Hospital', 'Kirkos Clinic'], array_column(array_column($list, 'hospital'), 'name'));
        $this->assertArrayNotHasKey('allergies', $list[0]); // oversight only, no clinical details

        $this->actingAs($platform)->getJson("/api/admin/patients?tenant_id={$kirkos->id}")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.full_name', 'Meron Alemu');
        $this->actingAs($platform)->getJson('/api/admin/patients?search=0911223344')->assertJsonPath('data.0.hospital.name', 'Entoto Hospital');

        // Viewing patient records is audited
        $this->assertTrue(ActivityLog::where('action', 'patients_viewed')->where('user_id', $platform->id)->exists());
        $this->actingAs($platform)->getJson('/api/dashboard')->assertJsonPath('data.stats.patients', 2);
    }

    public function test_only_the_platform_team_can_see_every_patient(): void
    {
        $hospital = $this->tenant('hospital');
        $this->actingAs($this->userWithRole(User::ROLE_HOSPITAL_ADMIN, $hospital))->getJson('/api/admin/patients')->assertForbidden();
        $this->actingAs($this->userWithRole(User::ROLE_DOCTOR, $hospital))->getJson('/api/admin/patients')->assertForbidden();
        $this->actingAs($this->userWithRole(User::ROLE_CUSTOMER))->getJson('/api/admin/patients')->assertForbidden();
    }
}
