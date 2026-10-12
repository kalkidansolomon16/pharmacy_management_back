<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

/** Subscription plans: staff accounts, locations, stores and modules are enforced on the server. */
class PlanLimitsTest extends TestCase
{
    private function subscribe(Tenant $tenant, array $plan): Plan
    {
        $plan = Plan::create($plan + ['code' => fake()->unique()->slug(2), 'name' => 'Basic', 'price_monthly' => 1500, 'is_active' => true]);
        Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);

        return $plan;
    }

    private function member(string $email, string $role = User::ROLE_STAFF): array
    {
        return ['name' => 'New Person', 'email' => $email, 'role' => $role, 'password' => 'Secret123'];
    }

    public function test_staff_accounts_stop_at_the_plan_limit_and_reactivation_counts_too(): void
    {
        $pharmacy = $this->tenant('pharmacy');
        $this->subscribe($pharmacy, ['modules' => ['pharmacy', 'billing'], 'max_users' => 2]);
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $pharmacy);

        $second = $this->actingAs($admin)->postJson('/api/users', $this->member('second@test.et'))->assertCreated()->json('data');
        $this->actingAs($admin)->postJson('/api/users', $this->member('third@test.et'))
            ->assertUnprocessable()->assertJsonValidationErrors('plan');

        // Freeing a seat lets a new person in; bringing the old one back is then refused
        $this->actingAs($admin)->deleteJson("/api/users/{$second['id']}")->assertOk();
        $this->actingAs($admin)->postJson('/api/users', $this->member('third@test.et'))->assertCreated();
        $this->actingAs($admin)->putJson("/api/users/{$second['id']}", ['name' => 'New Person', 'email' => 'second@test.et', 'role' => User::ROLE_STAFF, 'status' => 'active'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan');

        $this->actingAs($admin)->getJson('/api/subscription')
            ->assertJsonPath('data.usage.users.used', 2)
            ->assertJsonPath('data.usage.users.limit', 2);
    }

    public function test_branches_and_stores_follow_the_plan(): void
    {
        $pharmacy = $this->tenant('pharmacy');
        $this->subscribe($pharmacy, ['modules' => ['pharmacy', 'billing'], 'max_branches' => 1, 'max_stores' => 2]);
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $pharmacy);

        $branch = [
            'name' => 'Second Branch', 'license_number' => 'EFDA/1', 'phone' => '0111234567', 'region' => 'Addis Ababa', 'city' => 'Addis Ababa',
            'admin' => ['name' => 'Branch Admin', 'email' => 'branch@test.et', 'phone' => '0911000111', 'password' => 'Secret123'],
        ];
        // One location = the head office only
        $this->actingAs($admin)->postJson('/api/organization/branches', $branch)->assertUnprocessable()->assertJsonValidationErrors('plan');

        Store::defaultFor($pharmacy->id); // every pharmacy has its default dispensary (store 1 of 2)
        $this->actingAs($admin)->postJson('/api/stores', ['name' => 'Back store', 'code' => 'BACK', 'type' => 'main'])->assertCreated();
        $this->actingAs($admin)->postJson('/api/stores', ['name' => 'Third', 'code' => 'THIRD', 'type' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan');
    }

    public function test_modules_outside_the_plan_cannot_be_used_or_switched_on(): void
    {
        $pharmacy = $this->tenant('pharmacy', ['modules' => ['pharmacy', 'billing', 'insurance']]);
        $this->subscribe($pharmacy, ['modules' => ['pharmacy', 'billing']]);
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $pharmacy);

        $this->actingAs($admin)->getJson('/api/payers')->assertForbidden()->assertJsonPath('code', 'module_disabled');
        $this->actingAs($admin)->putJson('/api/organization/settings', ['modules' => ['pharmacy', 'billing', 'insurance']])
            ->assertUnprocessable()->assertJsonValidationErrors('modules');
        $this->actingAs($admin)->getJson('/api/auth/me')->assertJsonPath('data.modules', ['pharmacy', 'billing']);
    }

    public function test_upgrading_switches_the_new_modules_on_for_the_business_and_its_branches(): void
    {
        $pharmacy = $this->tenant('pharmacy', ['modules' => ['pharmacy', 'billing']]);
        $branch = $this->tenant('pharmacy', ['parent_id' => $pharmacy->id, 'modules' => ['pharmacy', 'billing']]);
        $this->subscribe($pharmacy, ['modules' => ['pharmacy', 'billing']]);
        $pro = Plan::create(['code' => 'pro', 'name' => 'Pro', 'price_monthly' => 3500, 'modules' => ['pharmacy', 'billing', 'insurance'], 'is_active' => true]);
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $branchStaff = $this->userWithRole(User::ROLE_STAFF, $branch);

        $this->actingAs($branchStaff)->getJson('/api/payers')->assertForbidden(); // the branch runs on the head office's plan

        $this->actingAs($platform)->putJson('/api/admin/subscriptions/'.$pharmacy->subscription->id, ['plan_id' => $pro->id])->assertOk();

        $this->assertSame(['pharmacy', 'billing', 'insurance'], $pharmacy->fresh()->enabledModules());
        $this->assertContains('insurance', $branch->fresh()->enabledModules());
    }

    public function test_a_branch_is_read_only_when_its_head_office_subscription_lapses(): void
    {
        $pharmacy = $this->tenant('pharmacy');
        $branch = $this->tenant('pharmacy', ['parent_id' => $pharmacy->id]);
        $plan = $this->subscribe($pharmacy, ['modules' => ['pharmacy', 'billing']]);
        $pharmacy->subscription->update(['current_period_end' => now()->subDays(Subscription::GRACE_DAYS + 1)]);
        $staff = $this->userWithRole(User::ROLE_STAFF, $branch);
        $listing = $this->listing($branch);

        $this->actingAs($staff)->postJson('/api/pos/sales', ['items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 1]], 'payment_method' => 'cash'])
            ->assertStatus(402);
        $this->assertNotNull($plan);
    }

    public function test_organizations_without_a_subscription_are_not_limited(): void
    {
        $pharmacy = $this->tenant('pharmacy');
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $pharmacy);

        foreach (range(1, 3) as $i) {
            $this->actingAs($admin)->postJson('/api/users', $this->member("p{$i}@test.et"))->assertCreated();
        }
    }

    public function test_new_organizations_get_the_plans_free_trial(): void
    {
        $service = app(\App\Services\Platform\SubscriptionService::class);
        $month = Plan::create(['code' => 'm', 'name' => 'Monthly', 'price_monthly' => 1500, 'trial_days' => 30, 'modules' => ['pharmacy'], 'is_active' => true]);
        $none = Plan::create(['code' => 'n', 'name' => 'No trial', 'price_monthly' => 1500, 'trial_days' => 0, 'modules' => ['pharmacy'], 'is_active' => true]);

        $sub = $service->startTrial($this->tenant('pharmacy'), $month);
        $this->assertSame('trial', $sub->status);
        $this->assertSame(30, (int) round(now()->diffInDays($sub->trial_ends_at)));

        $this->assertTrue($service->startTrial($this->tenant('pharmacy'), $none)->trial_ends_at->isToday());

        $this->actingAs($this->userWithRole(User::ROLE_SUPER_ADMIN))->getJson('/api/admin/plans')->assertJsonPath('data.0.trial_days', 30);
    }

    public function test_manual_invoices_must_charge_something(): void
    {
        $hospital = $this->tenant('hospital');
        $admin = $this->userWithRole(User::ROLE_HOSPITAL_ADMIN, $hospital);

        $this->actingAs($admin)->postJson('/api/invoices', ['customer_name' => 'abebe', 'lines' => [['description' => 'test', 'quantity' => 1, 'unit_price' => 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
    }
}
