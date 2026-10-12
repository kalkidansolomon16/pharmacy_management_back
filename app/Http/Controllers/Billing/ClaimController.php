<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimResource;
use App\Models\Claim;
use App\Models\Order;
use App\Models\Payer;
use App\Services\Billing\InsuranceService;
use App\Services\Reports\ReportExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClaimController extends Controller
{
    public function __construct(private InsuranceService $insurance) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Claim::class);

        return ClaimResource::collection(Claim::with('payer')
            ->when($request->query('payer_id'), fn ($q, $id) => $q->where('payer_id', $id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate($this->perPage(20)));
    }

    /** Generate the monthly statement for a payer. */
    public function store(Request $request)
    {
        $this->authorize('create', Claim::class);
        $data = $request->validate([
            'payer_id' => ['required', 'integer'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        $claim = $this->insurance->generateClaim(Payer::findOrFail($data['payer_id']), CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']));

        return $this->respond(new ClaimResource($claim->load('payer')), "Claim {$claim->claim_number} prepared: {$claim->invoices_count} invoices, ETB ".number_format((float) $claim->total_amount, 2).'.', 201);
    }

    public function show(Claim $claim)
    {
        $this->authorize('view', $claim);

        return new ClaimResource($claim->load('payer', 'invoices.membership'));
    }

    public function submit(Claim $claim)
    {
        $this->authorize('update', $claim);

        return $this->respond(new ClaimResource($this->insurance->submit($claim)->load('payer')), 'Claim submitted to the payer.');
    }

    public function pay(Request $request, Claim $claim)
    {
        $this->authorize('update', $claim);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'rejected' => ['nullable', 'numeric', 'min:0'],
            'method' => ['required', Rule::in(Order::PAYMENT_METHODS)],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);
        $claim = $this->insurance->recordClaimPayment($claim, (float) $data['amount'], (float) ($data['rejected'] ?? 0), $data['method'], $data['reference'] ?? null);

        return $this->respond(new ClaimResource($claim->load('payer')), 'Reimbursement recorded.');
    }

    /** Claim statement to send to the payer (PDF / Excel). */
    public function export(Request $request, Claim $claim, ReportExporter $exporter)
    {
        $this->authorize('view', $claim);
        $format = $request->validate(['format' => ['required', 'in:csv,pdf']])['format'];
        $claim->load('payer', 'invoices.membership', 'invoices.items');

        $rows = $claim->invoices->sortBy('issued_at')->map(fn ($i) => [
            'date' => $i->issued_at->format('Y-m-d'),
            'invoice' => $i->invoice_number,
            'member' => $i->membership?->member_number,
            'beneficiary' => $i->membership?->beneficiary_name ?? $i->customer_name,
            'services' => $i->items->pluck('description')->implode('; '),
            'total' => number_format((float) $i->total, 2),
            'copay' => number_format((float) $i->patient_share, 2),
            'claimed' => number_format((float) $i->payer_share, 2),
        ]);

        return $exporter->export($format, "Claim {$claim->claim_number} - {$claim->payer->name}",
            'Services from '.$claim->period_start->format('d M Y').' to '.$claim->period_end->format('d M Y'), [
                'date' => 'Date', 'invoice' => 'Invoice', 'member' => 'Member no.', 'beneficiary' => 'Beneficiary',
                'services' => 'Services', 'total' => 'Total (ETB)', 'copay' => 'Co-pay', 'claimed' => 'Claimed (ETB)',
            ], $rows, [
                'Payer' => $claim->payer->name,
                'Invoices' => $claim->invoices_count,
                'Total claimed (ETB)' => number_format((float) $claim->total_amount, 2),
                'Paid to date (ETB)' => number_format((float) $claim->paid_amount, 2),
            ]);
    }
}
