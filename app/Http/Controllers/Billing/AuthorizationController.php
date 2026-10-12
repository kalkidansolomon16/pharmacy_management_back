<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayerAuthorizationResource;
use App\Models\PayerAuthorization;
use App\Services\Billing\BillingService;
use Illuminate\Http\Request;

class AuthorizationController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', PayerAuthorization::class);

        return PayerAuthorizationResource::collection(PayerAuthorization::with('payer', 'invoice', 'requester:id,name')
            ->when($request->query('status', 'pending'), fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
            ->latest()->paginate($this->perPage(25)));
    }

    public function decide(Request $request, PayerAuthorization $authorization, BillingService $billing)
    {
        $this->authorize('update', $authorization);
        $data = $request->validate([
            'approved' => ['required', 'boolean'],
            'reference_number' => ['nullable', 'required_if:approved,true', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $authorization = $billing->decideAuthorization($authorization, $data['approved'], $data['reference_number'] ?? null, $data['notes'] ?? null);

        return $this->respond(new PayerAuthorizationResource($authorization->load('payer', 'invoice')),
            $data['approved'] ? 'Approved - the payer share will be claimed.' : 'Rejected - the patient pays the full amount.');
    }
}
