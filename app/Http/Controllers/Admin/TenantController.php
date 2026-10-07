<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\Auth\RegistrationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Tenant::class);

        $tenants = Tenant::query()
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('license_number', 'like', "%{$term}%")
                ->orWhere('city', 'like', "%{$term}%")))
            ->withCount(['users', 'pharmacyMedicines'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate($this->perPage());

        return TenantResource::collection($tenants);
    }

    public function show(Tenant $tenant)
    {
        $this->authorize('view', $tenant);

        return new TenantResource($tenant->loadCount(['users', 'pharmacyMedicines']));
    }

    public function update(UpdateOrganizationRequest $request, Tenant $tenant)
    {
        $this->authorize('update', $tenant);
        $tenant->update($request->safe()->except('status'));

        return $this->respond(new TenantResource($tenant), 'Organization updated.');
    }

    public function updateStatus(Request $request, Tenant $tenant, RegistrationService $registration)
    {
        $this->authorize('moderate', $tenant);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended', 'rejected', 'pending'])]]);

        $registration->changeTenantStatus($tenant, $data['status']);

        return $this->respond(new TenantResource($tenant), match ($data['status']) {
            'active' => "{$tenant->name} has been approved.",
            'suspended' => "{$tenant->name} has been suspended.",
            'rejected' => "{$tenant->name} has been rejected.",
            default => "{$tenant->name} moved back to pending.",
        });
    }
}
