<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanChangeRequest;
use App\Services\Platform\PlanChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Plan upgrades / downgrades: organizations ask, the platform team decides.
 */
class PlanChangeController extends Controller
{
    public function __construct(private PlanChangeService $changes) {}

    /** Organization: what would change on the chosen plan. */
    public function preview(Request $request)
    {
        Gate::authorize('subscription.view');
        $data = $request->validate(['plan_id' => ['required', 'integer', 'exists:plans,id']]);

        return $this->respond($this->changes->preview($request->user()->tenant, Plan::findOrFail($data['plan_id'])));
    }

    /** Organization: ask the platform team to move to another plan. */
    public function store(Request $request)
    {
        $this->authorizeOrganization($request);
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $change = $this->changes->request($request->user()->tenant, Plan::findOrFail($data['plan_id']), $request->user(), $data['note'] ?? null);

        return $this->respond($this->present($change), 'Request sent. MedLink will review it and let you know by email.', 201);
    }

    /** Organization: withdraw a waiting request or a scheduled downgrade. */
    public function cancel(Request $request, int $id)
    {
        $this->authorizeOrganization($request);
        $subscription = $this->changes->subscriptionOf($request->user()->tenant);
        $change = PlanChangeRequest::where('subscription_id', $subscription->id)->findOrFail($id);

        return $this->respond($this->present($this->changes->cancel($change)), 'Plan change cancelled.');
    }

    /** Platform team: requests from every organization, waiting ones first. */
    public function index(Request $request)
    {
        Gate::authorize('platform.plans');
        $data = $request->validate(['status' => ['nullable', 'in:pending,scheduled,applied,rejected,cancelled,open']]);

        $rows = PlanChangeRequest::with('tenant:id,name,type,city', 'fromPlan', 'toPlan', 'requester:id,name,email', 'decider:id,name', 'subscription')
            ->when($data['status'] ?? null, fn ($q, $s) => $s === 'open' ? $q->open() : $q->where('status', $s))
            ->orderByRaw("case status when 'pending' then 0 when 'scheduled' then 1 else 2 end")
            ->latest()
            ->paginate($this->perPage(20));

        return response()->json([
            'data' => $rows->getCollection()->map(fn ($r) => $this->present($r)),
            'meta' => [
                'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(),
                'total' => $rows->total(), 'from' => $rows->firstItem(), 'to' => $rows->lastItem(),
                'pending' => PlanChangeRequest::where('status', 'pending')->count(),
            ],
        ]);
    }

    public function approve(Request $request, PlanChangeRequest $planChangeRequest)
    {
        Gate::authorize('platform.plans');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $change = $this->changes->approve($planChangeRequest, $request->user(), $data['note'] ?? null);

        return $this->respond($this->present($change), $change->status === 'scheduled'
            ? 'Approved - the downgrade happens on '.$change->effective_at->format('j M Y').', when the paid period ends.'
            : 'Approved - '.$change->tenant->name.' is now on '.$change->toPlan->name.'.');
    }

    public function reject(Request $request, PlanChangeRequest $planChangeRequest)
    {
        Gate::authorize('platform.plans');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->respond($this->present($this->changes->reject($planChangeRequest, $request->user(), $data['reason'])), 'Request rejected.');
    }

    /** Changing the plan is for the people who run the organization. */
    private function authorizeOrganization(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->tenant_id && ($user->can('organization.manage') || $user->can('owner.dashboard')), 403, 'Only the organization admin or owner can change the plan.');
    }

    public static function present(PlanChangeRequest $r): array
    {
        $r->loadMissing('tenant', 'fromPlan', 'toPlan', 'requester', 'decider', 'subscription');

        return [
            'id' => $r->id,
            'status' => $r->status,
            'direction' => $r->direction,
            'note' => $r->note,
            'admin_note' => $r->admin_note,
            'effective_at' => $r->effective_at,
            'decided_at' => $r->decided_at,
            'applied_at' => $r->applied_at,
            'created_at' => $r->created_at,
            'tenant' => $r->tenant ? ['id' => $r->tenant->id, 'name' => $r->tenant->name, 'type' => $r->tenant->type, 'city' => $r->tenant->city] : null,
            'from_plan' => $r->fromPlan ? ['id' => $r->fromPlan->id, 'name' => $r->fromPlan->name, 'price_monthly' => (float) $r->fromPlan->price_monthly] : null,
            'to_plan' => ['id' => $r->toPlan->id, 'name' => $r->toPlan->name, 'price_monthly' => (float) $r->toPlan->price_monthly],
            'requested_by' => $r->requester ? ['id' => $r->requester->id, 'name' => $r->requester->name, 'email' => $r->requester->email] : null,
            'decided_by' => $r->decider?->name,
            'subscription' => $r->subscription ? ['status' => $r->subscription->status, 'ends_at' => $r->subscription->endsAt()] : null,
        ];
    }
}
