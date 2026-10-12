<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\PurchaseOrderRequest;
use App\Http\Requests\Inventory\ReceiveGoodsRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Inventory\PurchaseService;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseService $purchasing) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        return PurchaseOrderResource::collection(PurchaseOrder::with('supplier', 'store')->withCount('items')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate($this->perPage(20)));
    }

    /** Low-stock medicines with suggested order quantities. */
    public function suggestions()
    {
        $this->authorize('create', PurchaseOrder::class);

        return $this->respond($this->purchasing->suggestions());
    }

    public function store(PurchaseOrderRequest $request)
    {
        $po = $this->purchasing->create(Supplier::findOrFail($request->input('supplier_id')), $request->input('items'),
            $request->safe()->only(['store_id', 'expected_at', 'notes']));

        return $this->respond(new PurchaseOrderResource($po), "Purchase order {$po->po_number} created.", 201);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('view', $purchaseOrder);

        return new PurchaseOrderResource($purchaseOrder->load('supplier', 'store', 'items.listing.medicine', 'creator:id,name'));
    }

    public function send(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);

        return $this->respond(new PurchaseOrderResource($this->purchasing->send($purchaseOrder)->load('supplier', 'items.listing.medicine')), 'Marked as sent to the supplier.');
    }

    public function receive(ReceiveGoodsRequest $request, PurchaseOrder $purchaseOrder)
    {
        $po = $this->purchasing->receive($purchaseOrder, $request->input('lines'));

        return $this->respond(new PurchaseOrderResource($po), $po->status === 'received' ? 'All goods received.' : 'Goods received - order partially fulfilled.');
    }

    public function cancel(PurchaseOrder $purchaseOrder)
    {
        $this->authorize('update', $purchaseOrder);

        return $this->respond(new PurchaseOrderResource($this->purchasing->cancel($purchaseOrder)), 'Purchase order cancelled.');
    }
}
