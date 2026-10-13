<?php

namespace App\Services\Platform;

use App\Exceptions\BusinessRuleException;
use App\Models\Plan;
use App\Models\PlanChangeRequest;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PlanChangeDecided;
use App\Notifications\PlanChangeRequested;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Organizations ask to upgrade or downgrade; the platform team approves or rejects.
 * Upgrades apply as soon as they are approved. Downgrades wait until the paid period ends,
 * so nobody loses what they already paid for. Changing plan never deletes data.
 */
class PlanChangeService
{
    public function __construct(private SubscriptionService $subscriptions, private PlanLimits $limits, private ActivityLogger $logger) {}

    /** What moving to `$plan` would mean for this organization. */
    public function preview(Tenant $tenant, Plan $plan): array
    {
        $subscription = $this->subscriptionOf($tenant);
        $current = $subscription->plan;
        $type = $subscription->tenant->type;
        $before = $this->modulesFor($current, $type);
        $after = $this->modulesFor($plan, $type);
        $direction = $this->direction($current, $plan);

        $overLimit = [];
        foreach ($this->limits->usage($subscription->tenant) as $key => $row) {
            $limit = $plan->{PlanLimits::RESOURCES[$key]['column']};
            if ($limit !== null && $row['used'] > $limit) {
                $overLimit[] = ['resource' => $key, 'label' => $row['label'], 'used' => $row['used'], 'limit' => (int) $limit];
            }
        }

        return [
            'direction' => $direction,
            'from_plan' => $current ? ['id' => $current->id, 'name' => $current->name, 'price_monthly' => (float) $current->price_monthly] : null,
            'to_plan' => ['id' => $plan->id, 'name' => $plan->name, 'price_monthly' => (float) $plan->price_monthly],
            'price_difference' => round((float) $plan->price_monthly - (float) ($current?->price_monthly ?? 0), 2),
            'modules_gained' => array_values(array_diff($after, $before)),
            'modules_lost' => array_values(array_diff($before, $after)),
            'limits' => collect(PlanLimits::RESOURCES)->map(fn ($meta, $key) => [
                'label' => $meta['label'],
                'from' => $current?->{$meta['column']},
                'to' => $plan->{$meta['column']},
            ])->all(),
            'over_limit' => $overLimit,
            // When it would take effect if approved
            'applies' => $direction === 'downgrade' && $this->paidUntilFuture($subscription) ? 'period_end' : 'on_approval',
            'period_ends_at' => $subscription->endsAt()?->toIso8601String(),
        ];
    }

    public function request(Tenant $tenant, Plan $plan, User $by, ?string $note = null): PlanChangeRequest
    {
        $subscription = $this->subscriptionOf($tenant);
        if (! $plan->is_active) {
            throw BusinessRuleException::withErrors('plan_id', 'That plan is no longer offered.');
        }
        if (! $this->fits($plan, $subscription->tenant->type)) {
            throw BusinessRuleException::withErrors('plan_id', "The {$plan->name} plan is not made for a {$subscription->tenant->type}.");
        }
        if ($plan->id === $subscription->plan_id) {
            throw BusinessRuleException::withErrors('plan_id', "You are already on the {$plan->name} plan.");
        }
        if (PlanChangeRequest::where('subscription_id', $subscription->id)->open()->exists()) {
            throw BusinessRuleException::withErrors('plan_id', 'You already have a plan change waiting. Cancel it first to ask for a different plan.');
        }

        $request = PlanChangeRequest::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'from_plan_id' => $subscription->plan_id,
            'to_plan_id' => $plan->id,
            'direction' => $this->direction($subscription->plan, $plan),
            'status' => 'pending',
            'note' => $note,
            'requested_by' => $by->id,
        ]);
        $this->logger->log('plan_change_requested', $subscription, ['from' => $subscription->plan?->name, 'to' => $plan->name]);

        $request->load('tenant', 'fromPlan', 'toPlan', 'requester');
        User::role(User::ROLE_SUPER_ADMIN)->where('status', 'active')->get()->each->notify(new PlanChangeRequested($request));

        return $request;
    }

    public function approve(PlanChangeRequest $request, User $by, ?string $adminNote = null): PlanChangeRequest
    {
        $this->ensureStatus($request, ['pending']);
        $subscription = $request->subscription;

        DB::transaction(function () use ($request, $by, $adminNote, $subscription) {
            $request->update(['decided_by' => $by->id, 'decided_at' => now(), 'admin_note' => $adminNote]);
            if ($request->direction === 'downgrade' && $this->paidUntilFuture($subscription)) {
                $request->update(['status' => 'scheduled', 'effective_at' => $subscription->endsAt()]);
            } else {
                $this->apply($request, notify: false);
            }
        });
        $this->logger->log('plan_change_approved', $subscription, ['to' => $request->toPlan->name, 'status' => $request->status]);
        $this->notifyOrganization($request->refresh());

        return $request;
    }

    public function reject(PlanChangeRequest $request, User $by, string $reason): PlanChangeRequest
    {
        $this->ensureStatus($request, ['pending']);
        $request->update(['status' => 'rejected', 'admin_note' => $reason, 'decided_by' => $by->id, 'decided_at' => now()]);
        $this->logger->log('plan_change_rejected', $request->subscription, ['to' => $request->toPlan->name, 'reason' => $reason]);
        $this->notifyOrganization($request);

        return $request;
    }

    /** The organization withdraws its request (also a scheduled downgrade, before it happens). */
    public function cancel(PlanChangeRequest $request): PlanChangeRequest
    {
        $this->ensureStatus($request, PlanChangeRequest::OPEN_STATUSES);
        $request->update(['status' => 'cancelled']);
        $this->logger->log('plan_change_cancelled', $request->subscription, ['to' => $request->toPlan->name]);

        return $request;
    }

    /** Daily: carry out approved downgrades whose paid period has ended. */
    public function applyDue(): int
    {
        $count = 0;
        PlanChangeRequest::with('subscription', 'toPlan')->where('status', 'scheduled')->where('effective_at', '<=', now())
            ->get()->each(function (PlanChangeRequest $r) use (&$count) {
                $this->apply($r);
                $count++;
            });

        return $count;
    }

    public function apply(PlanChangeRequest $request, bool $notify = true): void
    {
        $this->subscriptions->changePlan($request->subscription, $request->toPlan);
        $request->update(['status' => 'applied', 'applied_at' => now(), 'effective_at' => $request->effective_at ?? now()]);
        if ($notify) {
            $this->notifyOrganization($request);
        }
    }

    public function subscriptionOf(Tenant $tenant): Subscription
    {
        $subscription = $tenant->billingSubscription();
        if (! $subscription) {
            throw BusinessRuleException::withErrors('plan_id', 'Your organization has no subscription yet. Contact MedLink support to choose a plan.');
        }

        return $subscription->loadMissing('plan', 'tenant');
    }

    private function notifyOrganization(PlanChangeRequest $request): void
    {
        $request->loadMissing('tenant', 'fromPlan', 'toPlan');
        User::where('tenant_id', $request->tenant_id)->where('status', 'active')->permission('subscription.view')->get()
            ->each->notify(new PlanChangeDecided($request));
    }

    private function ensureStatus(PlanChangeRequest $request, array $allowed): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw BusinessRuleException::withErrors('status', "This request is already {$request->status}.");
        }
    }

    private function direction(?Plan $from, Plan $to): string
    {
        return (float) $to->price_monthly >= (float) ($from?->price_monthly ?? 0) ? 'upgrade' : 'downgrade';
    }

    /** The organization has paid time left that it keeps on its current plan. */
    private function paidUntilFuture(Subscription $subscription): bool
    {
        return $subscription->status === 'active' && $subscription->current_period_end?->isFuture();
    }

    /** The module a pharmacy / hospital cannot work without. */
    public const CORE_MODULE = [Tenant::TYPE_PHARMACY => 'pharmacy', Tenant::TYPE_HOSPITAL => 'clinical'];

    /** A pharmacy can only move to plans with the pharmacy module, a hospital to plans with the clinic. */
    public function fits(Plan $plan, string $type): bool
    {
        $core = self::CORE_MODULE[$type] ?? null;

        return ! $core || in_array($core, $plan->modules ?? [], true);
    }

    private function modulesFor(?Plan $plan, string $type): array
    {
        $modules = array_values(array_intersect($plan?->modules ?? [], Tenant::MODULES));

        return $type === Tenant::TYPE_PHARMACY ? array_values(array_diff($modules, ['clinical', 'lab'])) : $modules;
    }
}
