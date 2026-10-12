<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\NewTenantRegistered;
use App\Notifications\TenantStatusChanged;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Organizations hear back (in the app and by email) and can fix a rejected registration. */
class TenantApprovalNoticeTest extends TestCase
{
    public function test_rejecting_needs_a_reason_and_the_team_is_emailed_with_it(): void
    {
        Notification::fake();
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $tenant = $this->tenant('pharmacy', ['status' => 'pending']);
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $tenant);

        $this->actingAs($platform)->patchJson("/api/admin/tenants/{$tenant->id}/status", ['status' => 'rejected'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($platform)->patchJson("/api/admin/tenants/{$tenant->id}/status", ['status' => 'rejected', 'reason' => 'Licence number not found in EFDA register.'])
            ->assertOk();

        $this->assertSame('Licence number not found in EFDA register.', $tenant->fresh()->status_reason);
        Notification::assertSentTo($admin, TenantStatusChanged::class, function ($n, $channels) use ($admin) {
            $mail = $n->toMail($admin);

            return in_array('mail', $channels, true)
                && str_contains(implode(' ', $mail->introLines), 'Licence number not found in EFDA register.')
                && $mail->actionText === 'Fix and resubmit';
        });

        // The team sees the reason on the waiting page
        $this->actingAs($admin)->getJson('/api/auth/me')->assertJsonPath('data.tenant.status_reason', 'Licence number not found in EFDA register.');
    }

    public function test_approval_is_emailed_and_clears_an_old_reason(): void
    {
        Notification::fake();
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $tenant = $this->tenant('hospital', ['status' => 'rejected', 'status_reason' => 'Old reason']);
        $admin = $this->userWithRole(User::ROLE_HOSPITAL_ADMIN, $tenant);

        $this->actingAs($platform)->patchJson("/api/admin/tenants/{$tenant->id}/status", ['status' => 'active'])->assertOk();

        $this->assertNull($tenant->fresh()->status_reason);
        Notification::assertSentTo($admin, TenantStatusChanged::class, fn ($n, $channels) => in_array('mail', $channels, true)
            && str_contains($n->toMail($admin)->subject, 'is approved'));
    }

    public function test_a_rejected_organization_fixes_its_details_and_goes_back_to_review(): void
    {
        Notification::fake();
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $tenant = $this->tenant('pharmacy', ['status' => 'rejected', 'status_reason' => 'Wrong licence']);
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $tenant);
        $pharmacist = $this->userWithRole(User::ROLE_STAFF, $tenant);
        $body = ['name' => 'Fixed Pharmacy', 'license_number' => 'EFDA/RP/1234/2018', 'phone' => '0911223355', 'note' => 'Corrected the licence number'];

        $this->actingAs($pharmacist)->postJson('/api/organization/resubmit', $body)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/organization/resubmit', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors(['name', 'license_number', 'phone']);

        $this->actingAs($admin)->postJson('/api/organization/resubmit', $body)
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.license_number', 'EFDA/RP/1234/2018');

        $this->assertNull($tenant->fresh()->status_reason);
        Notification::assertSentTo($platform, NewTenantRegistered::class, fn ($n) => $n->resubmitted && $n->note === 'Corrected the licence number');

        // Only rejected registrations can be resubmitted
        $this->actingAs($admin)->postJson('/api/organization/resubmit', $body)->assertStatus(422);
    }
}
