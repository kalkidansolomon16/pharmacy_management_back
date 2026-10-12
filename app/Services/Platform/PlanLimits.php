<?php

namespace App\Services\Platform;

use App\Exceptions\BusinessRuleException;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;

/**
 * Enforces what a subscription plan includes: staff accounts, branches (locations) and stores.
 * Limits apply to the whole business - the head office and all its branches share one plan.
 * Organizations without a subscription (e.g. created before SaaS billing) are not limited.
 */
class PlanLimits
{
    public const RESOURCES = [
        'users' => ['column' => 'max_users', 'label' => 'staff accounts'],
        'branches' => ['column' => 'max_branches', 'label' => 'locations (head office + branches)'],
        'stores' => ['column' => 'max_stores', 'label' => 'stores'],
    ];

    public function plan(Tenant $tenant): ?Plan
    {
        return $tenant->billingSubscription()?->plan;
    }

    /** @return array<string, array{used:int, limit:?int, label:string}> */
    public function usage(Tenant $tenant): array
    {
        $plan = $this->plan($tenant);
        $root = $tenant->parent_id ? ($tenant->parent ?? $tenant) : $tenant;
        $family = $root->familyIds();

        $used = [
            'users' => User::whereIn('tenant_id', $family)->where('status', 'active')->count(),
            // Head office counts as the first location; rejected branch requests do not count
            'branches' => 1 + Tenant::where('parent_id', $root->id)->where('status', '!=', 'rejected')->count(),
            'stores' => Store::withoutGlobalScopes()->whereIn('tenant_id', $family)->count(),
        ];

        return collect(self::RESOURCES)->map(fn ($meta, $key) => [
            'used' => $used[$key],
            'limit' => $plan?->{$meta['column']},
            'label' => $meta['label'],
        ])->all();
    }

    /** Throws when adding `$adding` more of `$resource` would exceed the plan. */
    public function ensureCanAdd(Tenant $tenant, string $resource, int $adding = 1): void
    {
        $plan = $this->plan($tenant);
        $limit = $plan?->{self::RESOURCES[$resource]['column']};
        if ($limit === null) {
            return;
        }

        $used = $this->usage($tenant)[$resource]['used'];
        if ($used + $adding > $limit) {
            throw BusinessRuleException::withErrors('plan', sprintf(
                'Your %s plan includes %d %s and you are using %d. Upgrade your plan under Subscription to add more%s.',
                $plan->name, $limit, self::RESOURCES[$resource]['label'], $used,
                $resource === 'users' ? ', or deactivate someone who no longer needs access' : ''
            ));
        }
    }

    /** Modules the plan includes (null = no plan, nothing restricted). */
    public function modules(Tenant $tenant): ?array
    {
        return $this->plan($tenant)?->modules;
    }
}
