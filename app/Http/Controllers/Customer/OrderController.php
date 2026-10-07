<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\Request;

/**
 * A customer's own orders across all pharmacies.
 */
class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request)
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->with('pharmacy')
            ->withCount('items')
            ->when($request->query('status'), fn ($q, $status) => $status === 'open'
                ? $q->whereIn('status', Order::OPEN_STATUSES)
                : $q->where('status', $status))
            ->latest()
            ->paginate($this->perPage(10));

        return OrderResource::collection($orders);
    }

    public function store(PlaceOrderRequest $request)
    {
        $this->authorize('place', Order::class);
        $order = $this->orders->placeOnline($request->user(), $request->validated());

        return $this->respond(new OrderResource($order), "Order {$order->order_number} sent to {$order->pharmacy->name}.", 201);
    }

    public function show(Order $order)
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load('items.medicine', 'pharmacy', 'prescription'));
    }

    public function cancel(Request $request, Order $order)
    {
        $this->authorize('cancel', $order);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:191']]);

        return $this->respond(new OrderResource($this->orders->cancel($order, $data['reason'] ?? 'Cancelled by customer')), 'Your order was cancelled.');
    }
}
