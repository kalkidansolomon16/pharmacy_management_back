<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Platform\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Platform team: every organization's subscription, renewals, plan changes.
 */
class SubscriptionAdminController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function index(Request $request)
    {
        Gate::authorize('platform.plans');

        return SubscriptionResource::collection(Subscription::with('plan', 'tenant')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('current_period_end')->paginate($this->perPage(30)));
    }

    public function store(Request $request)
    {
        Gate::authorize('platform.plans');
        $data = $request->validate(['tenant_id' => ['required', 'exists:tenants,id'], 'plan_id' => ['required', 'exists:plans,id']]);
        $subscription = $this->subscriptions->startTrial(Tenant::findOrFail($data['tenant_id']), Plan::find($data['plan_id']));
        abort_unless($subscription, 422, 'This organization already has a subscription.');

        return $this->respond(new SubscriptionResource($subscription->load('plan', 'tenant')), 'Trial started.', 201);
    }

    public function pay(Request $request, Subscription $subscription)
    {
        Gate::authorize('platform.plans');
        $data = $request->validate([
            'months' => ['required', 'integer', 'between:1,24'],
            'amount' => ['required', 'numeric', 'min:0'],
            'method' => ['required', Rule::in(Order::PAYMENT_METHODS)],
            'reference' => ['nullable', 'string', 'max:80'],
            'plan_id' => ['nullable', 'exists:plans,id'],
        ]);
        $subscription = $this->subscriptions->recordPayment($subscription, $data['months'], (float) $data['amount'], $data['method'], $data['reference'] ?? null,
            isset($data['plan_id']) ? Plan::find($data['plan_id']) : null);

        return $this->respond(new SubscriptionResource($subscription->load('tenant', 'payments')), 'Payment recorded - subscription active until '.$subscription->current_period_end->toDateString().'.');
    }

    public function update(Request $request, Subscription $subscription)
    {
        Gate::authorize('platform.plans');
        $data = $request->validate(['plan_id' => ['nullable', 'exists:plans,id'], 'status' => ['nullable', Rule::in(['trial', 'active', 'past_due', 'cancelled'])]]);
        if (! empty($data['plan_id'])) {
            $this->subscriptions->changePlan($subscription, Plan::find($data['plan_id']));
        }
        if (! empty($data['status'])) {
            $subscription->update(['status' => $data['status']]);
        }

        return $this->respond(new SubscriptionResource($subscription->refresh()->load('plan', 'tenant')), 'Subscription updated.');
    }
}
