<?php

namespace App\Services\Platform;

use App\Exceptions\BusinessRuleException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SubscriptionExpiring;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * SaaS billing: trials, monthly renewals, plan changes and expiry reminders.
 */
class SubscriptionService
{
    public const DEFAULT_PLAN = ['pharmacy' => 'pharmacy-pro', 'hospital' => 'clinic'];

    public function __construct(private ActivityLogger $logger) {}

    public function startTrial(Tenant $tenant, ?Plan $plan = null): ?Subscription
    {
        $plan ??= Plan::where('code', self::DEFAULT_PLAN[$tenant->type] ?? '')->first();
        if (! $plan || $tenant->subscription()->exists()) {
            return null;
        }

        return Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trial',
            // The plan's own free trial (e.g. first month free); falls back to the platform default
            'trial_ends_at' => now()->addDays($plan->trial_days ?? config('medlink.trial_days')),
        ]);
    }

    public function recordPayment(Subscription $subscription, int $months, float $amount, string $method, ?string $reference, ?Plan $plan = null): Subscription
    {
        if ($months < 1 || $months > 24) {
            throw BusinessRuleException::withErrors('months', 'Between 1 and 24 months.');
        }

        DB::transaction(function () use ($subscription, $months, $amount, $method, $reference, $plan) {
            $currentEnd = $subscription->status === 'trial' ? null : $subscription->current_period_end;
            $start = $currentEnd && $currentEnd->isFuture() ? $currentEnd : now();
            $end = $start->copy()->addMonths($months);

            $subscription->payments()->create([
                'amount' => $amount, 'method' => $method, 'reference' => $reference, 'months' => $months,
                'period_start' => $start, 'period_end' => $end, 'recorded_by' => Auth::id(),
            ]);
            if ($plan && $plan->id !== $subscription->plan_id) {
                $this->syncModules($subscription->tenant, $plan);
            }
            $subscription->update([
                'plan_id' => $plan?->id ?? $subscription->plan_id,
                'status' => 'active',
                'current_period_start' => $subscription->current_period_start && $currentEnd?->isFuture() ? $subscription->current_period_start : $start,
                'current_period_end' => $end,
            ]);
            $this->logger->log('subscription_paid', $subscription, ['months' => $months, 'amount' => $amount, 'reference' => $reference]);
        });

        return $subscription->refresh()->load('plan');
    }

    public function changePlan(Subscription $subscription, Plan $plan): Subscription
    {
        $from = $subscription->plan?->name;
        $subscription->update(['plan_id' => $plan->id]);
        $this->syncModules($subscription->tenant, $plan);
        $this->logger->log('subscription_plan_changed', $subscription, ['from' => $from, 'to' => $plan->name]);

        return $subscription->load('plan');
    }

    /**
     * After a plan change the organization (and its branches) get exactly the plan's modules that fit their type:
     * an upgrade switches the new modules on, a downgrade switches the missing ones off. No data is deleted.
     */
    public function syncModules(Tenant $tenant, Plan $plan): void
    {
        foreach (Tenant::whereIn('id', $tenant->familyIds())->get() as $t) {
            $modules = array_values(array_intersect($plan->modules, Tenant::MODULES));
            if ($t->isPharmacy()) {
                $modules = array_values(array_diff($modules, ['clinical', 'lab'])); // pharmacies have no clinic
            }
            $t->update(['modules' => $modules]);
        }
    }

    /** Daily: flag lapsed subscriptions and remind admins a week before the end. */
    public function check(): array
    {
        $pastDue = 0;
        $reminded = 0;
        Subscription::with('tenant', 'plan')->whereIn('status', ['trial', 'active'])->each(function (Subscription $s) use (&$pastDue, &$reminded) {
            $days = $s->daysLeft();
            if ($days !== null && $days < 0) {
                $s->update(['status' => 'past_due']);
                $pastDue++;
            }
            if (in_array($days, [7, 3, 1], true)) {
                User::where('tenant_id', $s->tenant_id)->where('status', 'active')->permission('subscription.view')->get()
                    ->each->notify(new SubscriptionExpiring($s));
                $reminded++;
            }
        });

        // Approved downgrades whose paid period has ended
        $planChanges = app(PlanChangeService::class)->applyDue();

        return ['past_due' => $pastDue, 'reminded' => $reminded, 'plan_changes' => $planChanges];
    }
}
