<?php

namespace App\Http\Controllers\Billing;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SaveMembershipRequest;
use App\Http\Resources\PayerMembershipResource;
use App\Models\PayerMembership;
use App\Services\Billing\InsuranceService;
use App\Support\ReferenceGenerator;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', PayerMembership::class);

        return PayerMembershipResource::collection(PayerMembership::with('payer')
            ->when($request->query('patient_id'), fn ($q, $id) => $q->where('patient_id', $id))
            ->when($request->query('payer_id'), fn ($q, $id) => $q->where('payer_id', $id))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('member_number', 'like', "%{$t}%")->orWhere('beneficiary_name', 'like', "%{$t}%")))
            ->latest()->paginate($this->perPage(25)));
    }

    public function store(SaveMembershipRequest $request)
    {
        $data = $request->validated();
        $data['phone'] = ReferenceGenerator::normalizePhone($data['phone'] ?? null);

        return $this->respond(new PayerMembershipResource(PayerMembership::create($data)->load('payer')), 'Coverage registered.', 201);
    }

    public function update(SaveMembershipRequest $request, PayerMembership $membership)
    {
        $data = $request->validated();
        $data['phone'] = ReferenceGenerator::normalizePhone($data['phone'] ?? null);
        $membership->update($data);

        return $this->respond(new PayerMembershipResource($membership->load('payer')), 'Coverage updated.');
    }

    /** Eligibility check at the counter: is this member number valid today? */
    public function verify(Request $request, InsuranceService $insurance)
    {
        $this->authorize('viewAny', PayerMembership::class);
        $data = $request->validate(['payer_id' => ['required', 'integer'], 'member_number' => ['required', 'string', 'max:40']]);
        $membership = $insurance->findCoverage((int) $data['payer_id'], $data['member_number']);
        if (! $membership) {
            throw BusinessRuleException::withErrors('member_number', 'No member with that number for this payer.');
        }

        return $this->respond(new PayerMembershipResource($membership->load('payer')), $membership->isValid() ? 'Coverage is valid.' : 'Coverage is NOT valid today.');
    }
}
