<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\TenantResource;
use App\Support\ReferenceGenerator;
use Illuminate\Http\Request;

/**
 * The signed-in user's own pharmacy / hospital profile.
 */
class OrganizationController extends Controller
{
    public function show(Request $request)
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 404);
        $this->authorize('view', $tenant);

        return new TenantResource($tenant->loadCount(['users', 'pharmacyMedicines']));
    }

    public function update(UpdateOrganizationRequest $request)
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 404);
        $this->authorize('update', $tenant);

        $data = $request->validated();
        if (isset($data['phone'])) {
            $data['phone'] = ReferenceGenerator::normalizePhone($data['phone']);
        }
        $tenant->update($data);

        return $this->respond(new TenantResource($tenant), 'Organization profile saved.');
    }
}
