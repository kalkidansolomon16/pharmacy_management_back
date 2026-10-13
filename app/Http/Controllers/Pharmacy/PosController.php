<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\WalkInSaleRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PharmacyMedicineResource;
use App\Http\Resources\PrescriptionResource;
use App\Models\Order;
use App\Models\PharmacyMedicine;
use App\Models\Prescription;
use App\Services\Orders\OrderService;
use App\Services\Prescriptions\PrescriptionService;
use Illuminate\Http\Request;

/**
 * Counter sales and prescription dispensing.
 */
class PosController extends Controller
{
    public function __construct(private OrderService $orders, private PrescriptionService $prescriptions) {}

    /** Quick product lookup for the till: name, brand or barcode scan. */
    public function products(Request $request)
    {
        $this->authorize('sell', Order::class);

        $listings = PharmacyMedicine::query()
            ->with('medicine')
            ->withAvailableQuantity()
            ->withNearestExpiry()
            ->whereHas('medicine', fn ($m) => $m->where('is_active', true)->search($request->query('q')))
            ->whereStock('>', 0)
            ->limit(20)
            ->get();

        return PharmacyMedicineResource::collection($listings);
    }

    public function sell(WalkInSaleRequest $request)
    {
        $this->authorize('sell', Order::class);
        $order = $this->orders->sellWalkIn($request->user(), $request->validated());
        $order->loadMissing('invoice');

        if (OrderService::awaitsCashier($order)) {
            return $this->respond(new OrderResource($order), "Sale {$order->order_number} sent to the cashier - ETB ".number_format((float) $order->total_amount, 2).'. Hand the medicine over once it is paid.', 201);
        }

        return $this->respond(new OrderResource($order), "Sale {$order->order_number} completed - ETB ".number_format((float) $order->total_amount, 2), 201);
    }

    /**
     * Counter sales at the cashier: waiting for payment, and today's paid ones (so the pharmacist knows what to hand over).
     */
    public function counterSales(Request $request)
    {
        abort_unless($request->user()->canAny(['billing.collect', 'billing.view', 'sales.create']), 403);
        $paid = $request->query('status') === 'paid';

        $orders = Order::query()
            ->with('items.medicine', 'creator:id,name')
            ->where('channel', 'walk_in')
            ->when($paid,
                fn ($q) => $q->whereIn('status', Order::FULFILLED_STATUSES)->whereDate('fulfilled_at', today())
                    ->whereExists(fn ($e) => $e->selectRaw('1')->from('activity_logs')->whereColumn('activity_logs.entity_id', 'orders.id')
                        ->where('activity_logs.action', 'sale_paid_at_cashier')),
                fn ($q) => $q->whereIn('status', Order::OPEN_STATUSES)->where('payment_status', 'unpaid'))
            ->latest($paid ? 'fulfilled_at' : 'created_at')
            ->limit(50)
            ->get();

        return OrderResource::collection($orders)->additional(['meta' => [
            'waiting' => Order::where('channel', 'walk_in')->whereIn('status', Order::OPEN_STATUSES)->where('payment_status', 'unpaid')->count(),
        ]]);
    }

    /** Cashier takes the money; stock is deducted and the receipt issued. */
    public function payCounterSale(Request $request, Order $order)
    {
        abort_unless($request->user()->can('billing.collect'), 403, 'Only the cashier can take payments.');
        $data = $request->validate([
            'method' => ['required', \Illuminate\Validation\Rule::in(Order::PAYMENT_METHODS)],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);
        $order = $this->orders->collectAtCashier($order, $data['method'], $data['reference'] ?? null);

        return $this->respond(new OrderResource($order), "Paid - receipt {$order->invoice?->invoice_number} issued. The pharmacist can hand over the medicine.");
    }

    /** The customer changed their mind before paying. */
    public function cancelCounterSale(Request $request, Order $order)
    {
        abort_unless($request->user()->canAny(['billing.collect', 'sales.create']), 403);
        abort_unless(OrderService::awaitsCashier($order), 422, 'Only a sale still waiting for payment can be cancelled here.');
        $order = $this->orders->cancel($order, $request->input('reason') ?: 'Cancelled before payment');

        return $this->respond(new OrderResource($order), "Sale {$order->order_number} cancelled - nothing was taken from stock.");
    }

    /**
     * Pharmacist scans / types a prescription code: show what was prescribed, what remains, and our stock for each line.
     */
    public function prescription(string $code)
    {
        $this->authorize('dispense', Prescription::class);

        $prescription = $this->prescriptions->findByCode($code);
        $listings = PharmacyMedicine::with('medicine')
            ->withAvailableQuantity()
            ->whereIn('medicine_id', $prescription->items->pluck('medicine_id'))
            ->get()
            ->keyBy('medicine_id');

        return $this->respond([
            'prescription' => new PrescriptionResource($prescription),
            'stock' => $prescription->items->mapWithKeys(fn ($item) => [
                $item->medicine_id => isset($listings[$item->medicine_id])
                    ? (new PharmacyMedicineResource($listings[$item->medicine_id]))->resolve()
                    : null,
            ]),
        ]);
    }
}
