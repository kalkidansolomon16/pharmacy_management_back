<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanChangeRequest;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PlanChangeDecided;
use App\Notifications\PlanChangeRequested;
use App\Services\Platform\SubscriptionService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Organizations ask to upgrade / downgrade; the platform team approves or rejects. */
class PlanChangeRequestTest extends TestCase
{
    private Plan $basic;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basic = Plan::create(['code' => 'pharmacy-basic', 'name' => 'Pharmacy Basic', 'price_monthly' => 1500, 'modules' => ['pharmacy', 'billing'], 'max_users' => 2, 'is_active' => true]);
        $this->pro = Plan::create(['code' => 'pharmacy-pro', 'name' => 'Pharmacy Pro', 'price_monthly' => 3500, 'modules' => ['pharmacy', 'billing', 'insurance'], 'max_users' => 20, 'is_active' => true]);
    }

    private function subscribed(Plan $plan, array $subscription = []): array
    {
        $pharmacy = $this->tenant('pharmacy', ['modules' => $plan->modules]);
        $s = Subscription::create($subscription + ['tenant_id' => $pharmacy->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now()->subDays(5), 'current_period_end' => now()->addDays(25)]);

        return [$pharmacy, $s, $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $pharmacy)];
    }

    public function test_an_upgrade_is_requested_and_applied_as_soon_as_it_is_approved(): void
    {
        Notification::fake();
        [$pharmacy, $subscription, $admin] = $this->subscribed($this->basic);
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->getJson("/api/subscription/change-preview?plan_id={$this->pro->id}")->assertOk()
            ->assertJsonPath('data.direction', 'upgrade')
            ->assertJsonPath('data.modules_gained', ['insurance'])
            ->assertJsonPath('data.price_difference', 2000)
            ->assertJsonPath('data.applies', 'on_approval');

        $id = $this->actingAs($admin)->postJson('/api/subscription/change-requests', ['plan_id' => $this->pro->id, 'note' => 'We opened a second shop'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        Notification::assertSentTo($platform, PlanChangeRequested::class, fn ($n, $channels) => in_array('mail', $channels, true));

        // Only one open request at a time; nothing changes until approved
        $this->actingAs($admin)->postJson('/api/subscription/change-requests', ['plan_id' => $this->pro->id])->assertUnprocessable();
        $this->assertSame($this->basic->id, $subscription->refresh()->plan_id);
        $this->actingAs($admin)->getJson('/api/subscription')->assertJsonPath('data.change_request.status', 'pending');

        $this->actingAs($platform)->getJson('/api/admin/plan-change-requests')->assertOk()
            ->assertJsonPath('meta.pending', 1)->assertJsonPath('data.0.tenant.name', $pharmacy->name);
        $this->actingAs($platform)->getJson('/api/dashboard')->assertJsonPath('data.plan_change_pending', 1)
            ->assertJsonPath('data.plan_change_requests.0.tenant', $pharmacy->name);
        $this->actingAs($platform)->postJson("/api/admin/plan-change-requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'applied');
        $this->actingAs($platform)->getJson('/api/dashboard')->assertJsonPath('data.plan_change_pending', 0);

        $this->assertSame($this->pro->id, $subscription->refresh()->plan_id);
        $this->assertContains('insurance', $pharmacy->refresh()->modules);
        Notification::assertSentTo($admin, PlanChangeDecided::class, fn ($n, $channels) => $n->request->status === 'applied' && in_array('mail', $channels, true));
    }

    public function test_a_downgrade_waits_for_the_end_of_the_paid_period(): void
    {
        Notification::fake();
        [$pharmacy, $subscription, $admin] = $this->subscribed($this->pro);
        $this->userWithRole(User::ROLE_STAFF, $pharmacy);
        $this->userWithRole(User::ROLE_STAFF, $pharmacy);
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        // Preview warns about what switches off and the staff limit
        $this->actingAs($admin)->getJson("/api/subscription/change-preview?plan_id={$this->basic->id}")->assertOk()
            ->assertJsonPath('data.direction', 'downgrade')
            ->assertJsonPath('data.modules_lost', ['insurance'])
            ->assertJsonPath('data.over_limit.0.resource', 'users')
            ->assertJsonPath('data.over_limit.0.used', 3)
            ->assertJsonPath('data.applies', 'period_end');

        $id = $this->actingAs($admin)->postJson('/api/subscription/change-requests', ['plan_id' => $this->basic->id])->assertCreated()->json('data.id');
        $this->actingAs($platform)->postJson("/api/admin/plan-change-requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->assertSame($this->pro->id, $subscription->refresh()->plan_id); // keeps what was paid for

        // The daily job does nothing early, then applies it when the period has ended
        app(SubscriptionService::class)->check();
        $this->assertSame('scheduled', PlanChangeRequest::find($id)->status);

        $this->travel(26)->days();
        $result = app(SubscriptionService::class)->check();
        $this->assertSame(1, $result['plan_changes']);
        $this->assertSame('applied', PlanChangeRequest::find($id)->status);
        $this->assertSame($this->basic->id, $subscription->refresh()->plan_id);
        $this->assertNotContains('insurance', $pharmacy->refresh()->modules);
        Notification::assertSentTo($admin, PlanChangeDecided::class, fn ($n) => $n->request->status === 'applied');
    }

    public function test_a_downgrade_during_a_trial_applies_right_away_and_requests_can_be_rejected_or_cancelled(): void
    {
        Notification::fake();
        [, $subscription, $admin] = $this->subscribed($this->pro, ['status' => 'trial', 'trial_ends_at' => now()->addDays(10)]);
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);

        // Cancelled by the organization
        $id = $this->actingAs($admin)->postJson('/api/subscription/change-requests', ['plan_id' => $this->basic->id])->json('data.id');
        $this->actingAs($admin)->postJson("/api/subscription/change-requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($platform)->postJson("/api/admin/plan-change-requests/{$id}/approve")->assertUnprocessable();

        // Rejected by the platform team, with a reason
        $id = $this->actingAs($admin)->postJson('/api/subscription/change-requests', ['plan_id' => $this->basic->id])->json('data.id');
        $this->actingAs($platform)->postJson("/api/admin/plan-change-requests/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($platform)->postJson("/api/admin/plan-change-requests/{$id}/reject", ['reason' => 'Please pay the open invoice first'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        Notification::assertSentTo($admin, PlanChangeDecided::class, fn ($n) => $n->request->status === 'rejected');

        // Approved during the trial: nothing paid to protect, so it applies now
        $id = $this->actingAs($admin)->postJson('/api/subscription/change-requests', ['plan_id' => $this->basic->id])->json('data.id');
        $this->actingAs($platform)->postJson("/api/admin/plan-change-requests/{$id}/approve")->assertJsonPath('data.status', 'applied');
        $this->assertSame($this->basic->id, $subscription->refresh()->plan_id);

        // Same plan is refused
        $this->actingAs($admin)->postJson('/api/subscription/change-requests', ['plan_id' => $this->basic->id])->assertUnprocessable();
    }

    public function test_only_organization_leaders_request_and_only_the_platform_team_decides(): void
    {
        [$pharmacy, , $admin] = $this->subscribed($this->basic);
        $staff = $this->userWithRole(User::ROLE_STAFF, $pharmacy);
        $other = Tenant::find($this->subscribed($this->basic)[0]->id);
        $otherAdmin = User::where('tenant_id', $other->id)->first();

        $this->actingAs($staff)->postJson('/api/subscription/change-requests', ['plan_id' => $this->pro->id])->assertForbidden();
        // A pharmacy cannot pick a clinic-only plan (it would switch its pharmacy off)
        $clinic = Plan::create(['code' => 'clinic', 'name' => 'Clinic', 'price_monthly' => 4500, 'modules' => ['clinical', 'lab', 'billing'], 'is_active' => true]);
        $this->actingAs($admin)->postJson('/api/subscription/change-requests', ['plan_id' => $clinic->id])->assertUnprocessable()->assertJsonValidationErrors('plan_id');
        $this->actingAs($admin)->getJson('/api/subscription')->assertJsonPath('data.core_module', 'pharmacy');
        $id = $this->actingAs($admin)->postJson('/api/subscription/change-requests', ['plan_id' => $this->pro->id])->assertCreated()->json('data.id');

        $this->actingAs($admin)->getJson('/api/admin/plan-change-requests')->assertForbidden();
        $this->actingAs($admin)->postJson("/api/admin/plan-change-requests/{$id}/approve")->assertForbidden();
        // Another organization cannot cancel it
        $this->actingAs($otherAdmin)->postJson("/api/subscription/change-requests/{$id}/cancel")->assertNotFound();
    }
}
