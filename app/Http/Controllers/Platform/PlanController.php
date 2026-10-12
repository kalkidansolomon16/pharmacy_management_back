<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index()
    {
        return PlanResource::collection(Plan::orderBy('sort')->get());
    }

    public function store(Request $request)
    {
        Gate::authorize('platform.plans');

        return $this->respond(new PlanResource(Plan::create($this->validated($request))), 'Plan created.', 201);
    }

    public function update(Request $request, Plan $plan)
    {
        Gate::authorize('platform.plans');
        $plan->update($this->validated($request, $plan));

        return $this->respond(new PlanResource($plan), 'Plan updated.');
    }

    private function validated(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:191'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'], // free trial for new organizations (0 = none)
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => [Rule::in(Tenant::MODULES)],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'max_branches' => ['nullable', 'integer', 'min:0'],
            'max_stores' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer'],
        ]);
    }
}
