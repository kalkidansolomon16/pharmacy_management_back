<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SavePayerRequest;
use App\Http\Resources\PayerResource;
use App\Models\Invoice;
use App\Models\Payer;
use Illuminate\Http\Request;

/**
 * Insurers, CBHI schemes and corporate credit agreements.
 */
class PayerController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Payer::class);

        $payers = Payer::withCount('memberships')
            ->addSelect(['outstanding_amount' => Invoice::selectRaw('coalesce(sum(payer_share - payer_paid - payer_rejected), 0)')
                ->whereColumn('payer_id', 'payers.id')->whereNotIn('status', ['draft', 'void'])])
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get();

        return PayerResource::collection($payers);
    }

    public function store(SavePayerRequest $request)
    {
        return $this->respond(new PayerResource(Payer::create($request->validated())), 'Payer added.', 201);
    }

    public function update(SavePayerRequest $request, Payer $payer)
    {
        $payer->update($request->validated());

        return $this->respond(new PayerResource($payer), 'Payer updated.');
    }
}
