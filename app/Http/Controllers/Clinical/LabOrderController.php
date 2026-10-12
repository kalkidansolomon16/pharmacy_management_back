<?php

namespace App\Http\Controllers\Clinical;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clinical\LabOrderRequest;
use App\Http\Requests\Clinical\LabResultRequest;
use App\Http\Resources\LabOrderResource;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\Clinical\LabService;
use Illuminate\Http\Request;

/**
 * Laboratory worklist: orders, specimen collection, result entry and verification.
 */
class LabOrderController extends Controller
{
    public function __construct(private LabService $lab) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', LabOrder::class);

        $orders = LabOrder::query()
            ->with('patient', 'orderer:id,name', 'items.test')
            ->when($request->query('status'), fn ($q, $s) => $s === 'open' ? $q->whereIn('status', ['ordered', 'collected']) : $q->where('status', $s))
            ->when($request->query('patient_id'), fn ($q, $id) => $q->where('patient_id', $id))
            ->when($request->query('search'), fn ($q, $t) => $q->where(fn ($w) => $w->where('order_number', 'like', "%{$t}%")
                ->orWhereHas('patient', fn ($p) => $p->where('full_name', 'like', "%{$t}%")->orWhere('mrn', 'like', "%{$t}%"))))
            ->orderByRaw("case when priority = 'urgent' then 0 else 1 end")
            ->latest()
            ->paginate($this->perPage(25));

        $orders->getCollection()->each(fn ($o) => $o->payment_state = $this->paymentState($o));
        $counts = LabOrder::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return LabOrderResource::collection($orders)->additional(['meta' => ['counts' => $counts]]);
    }

    public function store(LabOrderRequest $request)
    {
        $patient = Patient::findOrFail($request->input('patient_id'));
        $visit = $request->input('visit_id') ? Visit::findOrFail($request->input('visit_id')) : null;
        $order = $this->lab->order($patient, $visit, $request->input('tests'), $request->input('priority', 'routine'), $request->input('clinical_notes'));

        return $this->respond(new LabOrderResource($order), "Lab request {$order->order_number} sent to the laboratory.", 201);
    }

    public function show(LabOrder $order)
    {
        $this->authorize('view', $order);
        $order->load('patient', 'orderer:id,name', 'items.test', 'items.resulter:id,name', 'items.verifier:id,name');
        $order->payment_state = $this->paymentState($order);

        return new LabOrderResource($order);
    }

    public function collect(LabOrder $order)
    {
        $this->authorize('process', $order);

        return $this->respond(new LabOrderResource($this->lab->collect($order)->load('patient', 'items.test')), 'Specimen collected.');
    }

    public function result(LabResultRequest $request, LabOrder $order, LabOrderItem $item)
    {
        abort_unless($item->lab_order_id === $order->id, 404);
        $this->lab->result($item, $request->validated());

        return $this->respond(new LabOrderResource($order->refresh()->load('patient', 'items.test', 'items.resulter:id,name')), 'Result saved.');
    }

    public function verify(LabOrder $order, LabOrderItem $item)
    {
        $this->authorize('verify', $order);
        abort_unless($item->lab_order_id === $order->id, 404);
        $this->lab->verify($item);

        return $this->respond(new LabOrderResource($order->refresh()->load('patient', 'items.test', 'items.verifier:id,name')), 'Result verified.');
    }

    private function paymentState(LabOrder $order): array
    {
        $invoice = $this->lab->invoiceFor($order);

        return $invoice
            ? ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'settled' => $invoice->isPatientSettled(), 'balance' => $invoice->patientBalance(), 'covered' => (float) $invoice->payer_share > 0]
            : ['invoice_id' => null, 'settled' => true, 'balance' => 0, 'covered' => false];
    }
}
