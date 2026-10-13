<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SubscriptionController extends Controller
{
    /** The organization's own plan, status and payment history. */
    public function show(Request $request)
    {
        Gate::authorize('subscription.view');
        $tenant = $request->user()->tenant;
        $subscription = $tenant?->billingSubscription()?->load('plan', 'payments');

        return $this->respond([
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
            // How much of the plan is in use (staff accounts, locations, stores)
            'usage' => $tenant ? app(\App\Services\Platform\PlanLimits::class)->usage($tenant) : null,
            'is_branch' => (bool) $tenant?->parent_id,
            // The latest plan change request (waiting, scheduled, or the last decision)
            'change_request' => ($latest = $subscription ? \App\Models\PlanChangeRequest::where('subscription_id', $subscription->id)->latest('id')->first() : null)
                ? PlanChangeController::present($latest) : null,
            // Plans this organization can move to (a pharmacy cannot pick a clinic-only plan)
            'core_module' => \App\Services\Platform\PlanChangeService::CORE_MODULE[$subscription?->tenant?->type ?? $tenant?->type] ?? null,
            'can_change_plan' => (bool) ($request->user()->can('organization.manage') || $request->user()->can('owner.dashboard')),
            'plans' => PlanResource::collection(Plan::where('is_active', true)->orderBy('sort')->get()),
            'payment_instructions' => 'Pay by telebirr or CBE Birr to MedLink Ethiopia and send the transaction id to support; the platform team activates the period.',
        ]);
    }
}
