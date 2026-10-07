<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\Request;

/**
 * The pharmacy's order queue and lifecycle actions.
 */
class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $orders = Order::query()
            ->withCount('items')
            ->with('prescription:id,reference_code,status')
            ->when($request->query('status'), fn ($q, $status) => $status === 'open'
                ? $q->whereIn('status', Order::OPEN_STATUSES)
                : $q->where('status', $status))
            ->when($request->query('channel'), fn ($q, $channel) => $q->where('channel', $channel))
            ->when($request->query('payment_method'), fn ($q, $m) => $q->where('payment_method', $m))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('order_number', 'like', "%{$term}%")
                ->orWhere('customer_name', 'like', "%{$term}%")
                ->orWhere('customer_phone', 'like', "%{$term}%")))
            ->latest()
            ->paginate($this->perPage());

        return OrderResource::collection($orders)->additional([
            'meta' => ['counts' => Order::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')],
        ]);
    }

    public function show(Order $order)
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load('items.medicine', 'items.allocations.batch', 'prescription', 'creator', 'customer'));
    }

    public function confirm(Order $order)
    {
        $this->authorize('process', $order);

        return $this->respond(new OrderResource($this->orders->confirm($order)), 'Order confirmed. The customer has been notified.');
    }

    public function ready(Order $order)
    {
        $this->authorize('process', $order);

        return $this->respond(new OrderResource($this->orders->markReady($order)), 'Marked as ready for pickup.');
    }

    public function complete(Request $request, Order $order)
    {
        $this->authorize('process', $order);
        $request->validate(['allow_partial' => ['boolean']]);

        $order = $this->orders->complete($order, $request->boolean('allow_partial'));
        $message = $order->status === 'completed'
            ? 'Order dispensed in full. Stock was deducted first-expiry-first-out.'
            : 'Order partially dispensed. The customer was charged only for what was available.';

        return $this->respond(new OrderResource($order), $message);
    }

    public function reject(Request $request, Order $order)
    {
        $this->authorize('process', $order);
        $data = $request->validate(['reason' => ['required', 'string', 'max:191']]);

        return $this->respond(new OrderResource($this->orders->reject($order, $data['reason'])), 'Order rejected.');
    }

    public function cancel(Request $request, Order $order)
    {
        $this->authorize('cancel', $order);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:191']]);

        return $this->respond(new OrderResource($this->orders->cancel($order, $data['reason'] ?? null)), 'Order cancelled.');
    }
}
