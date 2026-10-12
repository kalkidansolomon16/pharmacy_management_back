<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\TenantResource;
use App\Rules\EthiopianPhone;
use App\Services\Auth\RegistrationService;
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

    /**
     * A rejected organization corrects its registration details and sends them back for review.
     * Available before approval (the rest of the workspace stays locked).
     */
    public function resubmit(Request $request, RegistrationService $registration)
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 404);
        abort_unless($request->user()->can('organization.manage'), 403, 'Only your organization administrator can resubmit.');
        abort_unless($tenant->status === 'rejected', 422, 'Only a rejected registration can be resubmitted.');

        if (is_string($request->input('email')) && $request->input('email') !== '') {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'license_number' => ['required', 'string', 'max:60'],
            'tin_number' => ['nullable', 'digits:10'],
            'phone' => ['required', EthiopianPhone::orLandline()],
            'email' => ['nullable', 'email', 'max:191', \App\Models\Tenant::uniqueEmailRule($tenant->id)],
            'address_line' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['email.unique' => 'Another organization already uses this email. Use your own organization email.']);
        $note = $data['note'] ?? null;
        unset($data['note']);

        $registration->resubmit($tenant, $data, $note);

        return $this->respond(new TenantResource($tenant->fresh()), 'Thank you - your details were sent for review again.');
    }
}
