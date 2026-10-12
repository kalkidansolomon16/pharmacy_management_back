<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\EthiopianPhone;
use App\Rules\UniquePhone;
use App\Services\ActivityLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

/**
 * Branches of an organization (each branch is its own workspace, approved by the platform team).
 */
class BranchController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('owner.dashboard');

        return TenantResource::collection($request->user()->tenant->branches()->withCount('users')->get());
    }

    public function store(Request $request, ActivityLogger $logger)
    {
        Gate::authorize('organization.manage');
        $parent = $request->user()->tenant;
        abort_if($parent->parent_id, 422, 'A branch cannot have its own branches.');
        app(\App\Services\Platform\PlanLimits::class)->ensureCanAdd($parent, 'branches');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'license_number' => ['required', 'string', 'max:60'],
            'phone' => ['required', EthiopianPhone::orLandline()],
            'region' => ['required', 'string', 'max:80'],
            'city' => ['required', 'string', 'max:80'],
            'sub_city' => ['nullable', 'string', 'max:80'],
            'woreda' => ['nullable', 'string', 'max:40'],
            'address_line' => ['nullable', 'string', 'max:191'],
            'admin.name' => ['required', 'string', 'max:120'],
            'admin.email' => ['required', 'email', 'unique:users,email'],
            'admin.phone' => ['required', new EthiopianPhone, new UniquePhone],
            'admin.password' => ['required', Password::min(8)->letters()->mixedCase()->numbers()],
        ]);

        $branch = DB::transaction(function () use ($parent, $data, $logger) {
            $branch = Tenant::create(collect($data)->except('admin')->all() + [
                'parent_id' => $parent->id, 'type' => $parent->type, 'status' => 'pending',
                'modules' => $parent->modules, 'tax_regime' => $parent->tax_regime, 'tin_number' => $parent->tin_number,
                'phone' => ReferenceGenerator::normalizePhone($data['phone']),
            ]);
            $admin = User::create([
                'tenant_id' => $branch->id, 'name' => $data['admin']['name'], 'email' => strtolower($data['admin']['email']),
                'phone' => ReferenceGenerator::normalizePhone($data['admin']['phone']), 'password' => $data['admin']['password'],
            ]);
            $admin->assignRole($parent->isPharmacy() ? User::ROLE_PHARMACY_ADMIN : User::ROLE_HOSPITAL_ADMIN);
            $logger->log('branch_requested', $branch, ['parent' => $parent->name]);

            return $branch;
        });

        return $this->respond(new TenantResource($branch), "{$branch->name} submitted for verification.", 201);
    }
}
