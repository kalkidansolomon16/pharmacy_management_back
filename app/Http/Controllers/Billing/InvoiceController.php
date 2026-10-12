<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\PaymentRequest;
use App\Http\Requests\Billing\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PayerMembership;
use App\Models\Service;
use App\Services\Billing\BillingService;
use App\Services\Fiscal\FiscalService;
use App\Support\ReferenceGenerator;
use Illuminate\Http\Request;

/**
 * Cashier desk: invoices (fiscal receipts), payments, voids.
 */
class InvoiceController extends Controller
{
    public function __construct(private BillingService $billing) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::query()
            ->with('payer:id,name,type')
            ->when($request->query('status'), fn ($q, $s) => match ($s) {
                'unpaid' => $q->whereIn('status', ['issued', 'partially_paid'])->whereColumn('amount_paid', '<', 'patient_share'),
                'awaiting_approval' => $q->where('status', 'awaiting_approval'),
                default => $q->where('status', $s),
            })
            ->when($request->query('fiscal'), fn ($q, $f) => $q->where('fiscal_status', $f))
            ->when($request->query('payer_id'), fn ($q, $p) => $q->where('payer_id', $p))
            ->when($request->query('patient_id'), fn ($q, $p) => $q->where('patient_id', $p))
            ->when($request->query('visit_id'), fn ($q, $v) => $q->where('visit_id', $v))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('issued_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('issued_at', '<=', $d))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('invoice_number', 'like', "%{$t}%")
                ->orWhere('customer_name', 'like', "%{$t}%")->orWhere('customer_phone', 'like', '%'.ltrim(preg_replace('/\D/', '', $t), '0').'%')))
            ->latest('issued_at')->latest('id')
            ->paginate($this->perPage(25));

        $summary = Invoice::whereDate('issued_at', today())->where('status', '!=', 'void')
            ->selectRaw('count(*) as invoices, coalesce(sum(total),0) as total')->first();

        return InvoiceResource::collection($invoices)->additional(['meta' => [
            'today' => ['invoices' => (int) $summary->invoices, 'total' => round((float) $summary->total, 2),
                'collected' => round((float) \App\Models\Payment::whereDate('paid_at', today())->whereNotNull('invoice_id')->sum('amount'), 2)],
            'unpaid_count' => Invoice::whereIn('status', ['issued', 'partially_paid'])->whereColumn('amount_paid', '<', 'patient_share')->count(),
            'awaiting_approval' => Invoice::where('status', 'awaiting_approval')->count(),
            'fiscal_pending' => Invoice::whereIn('fiscal_status', ['pending', 'failed'])->count(),
        ]]);
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        return new InvoiceResource($invoice->load('items', 'payments.receiver:id,name', 'payer', 'membership.payer', 'claim', 'authorizations.payer', 'creator:id,name'));
    }

    /** Manual invoice for services (procedures, consultations, other charges). */
    public function store(StoreInvoiceRequest $request)
    {
        $data = $request->validated();
        $services = Service::whereIn('id', collect($data['lines'])->pluck('service_id')->filter())->pluck('price', 'id');
        $gross = collect($data['lines'])->sum(function ($l) use ($services) {
            $price = ! empty($l['service_id']) ? ($services[$l['service_id']] ?? 0) : ($l['unit_price'] ?? 0);

            return (float) $price * (float) $l['quantity'];
        });
        if ($gross <= 0) {
            throw \App\Exceptions\BusinessRuleException::withErrors('lines', 'An invoice must charge something: every line has a zero price.');
        }
        $patient = ! empty($data['patient_id']) ? Patient::find($data['patient_id']) : null;
        $membership = ! empty($data['membership_id']) ? PayerMembership::with('payer')->findOrFail($data['membership_id']) : null;
        $services = Service::whereIn('id', collect($data['lines'])->pluck('service_id')->filter())->get()->keyBy('id');

        $lines = collect($data['lines'])->map(function ($l) use ($services) {
            $service = isset($l['service_id']) ? $services->get($l['service_id']) : null;

            return [
                'item_type' => $service ? ($service->category === 'registration' ? 'registration' : ($service->category === 'consultation' ? 'consultation' : 'procedure')) : ($l['item_type'] ?? 'other'),
                'description' => $service?->name ?? $l['description'],
                'quantity' => $l['quantity'],
                'unit_price' => $service ? $service->price : $l['unit_price'],
                'tax_category' => $service?->tax_category ?? ($l['tax_category'] ?? 'exempt'),
                'reference' => $service,
            ];
        })->all();

        $invoice = $this->billing->createInvoice($request->user()->tenant, [
            'patient_id' => $patient?->id,
            'visit_id' => $data['visit_id'] ?? null,
            'customer_name' => $patient?->full_name ?? $data['customer_name'],
            'customer_phone' => ReferenceGenerator::normalizePhone($patient?->phone ?? ($data['customer_phone'] ?? null)),
            'customer_tin' => $data['customer_tin'] ?? null,
        ], $lines, $membership, (float) ($data['discount'] ?? 0));

        return $this->respond(new InvoiceResource($invoice->load('items', 'payer')), "Invoice {$invoice->invoice_number} issued.", 201);
    }

    public function pay(PaymentRequest $request, Invoice $invoice)
    {
        $this->billing->recordPayment($invoice, (float) $request->input('amount'), $request->input('method'), $request->input('reference'), $request->input('notes'));

        return $this->respond(new InvoiceResource($invoice->refresh()->load('items', 'payments.receiver:id,name', 'payer')), 'Payment received.');
    }

    public function void(Request $request, Invoice $invoice)
    {
        $this->authorize('void', $invoice);
        $data = $request->validate(['reason' => ['required', 'string', 'max:191']]);

        return $this->respond(new InvoiceResource($this->billing->void($invoice, $data['reason'])->load('items')), 'Invoice voided.');
    }

    /** Retry Ministry of Revenue registration now (it is also retried automatically every 5 minutes). */
    public function registerFiscal(Invoice $invoice, FiscalService $fiscal)
    {
        $this->authorize('collect', $invoice);
        if ($invoice->fiscal_status === 'failed') {
            $fiscal->prepare($invoice->load('seller', 'items'));
        }
        $fiscal->register($invoice);

        return $this->respond(new InvoiceResource($invoice->refresh()->load('items')),
            $invoice->fiscal_status === 'accepted' ? 'Registered with the Ministry of Revenue.' : 'Still pending: '.$invoice->fiscal_error);
    }
}
