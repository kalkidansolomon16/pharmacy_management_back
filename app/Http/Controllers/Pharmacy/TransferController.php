<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\TransferRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Services\Inventory\TransferService;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(private TransferService $transfers) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', StockTransfer::class);

        return StockTransferResource::collection(StockTransfer::with('fromStore', 'toStore', 'requester:id,name')->withCount('items')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate($this->perPage(20)));
    }

    public function store(TransferRequest $request)
    {
        $transfer = $this->transfers->request(Store::findOrFail($request->input('from_store_id')), Store::findOrFail($request->input('to_store_id')),
            $request->input('items'), $request->input('notes'));

        return $this->respond(new StockTransferResource($transfer), "Transfer {$transfer->transfer_number} requested.", 201);
    }

    public function show(StockTransfer $transfer)
    {
        $this->authorize('view', $transfer);

        return new StockTransferResource($transfer->load('fromStore', 'toStore', 'items.listing.medicine', 'requester:id,name'));
    }

    public function dispatch(Request $request, StockTransfer $transfer)
    {
        $this->authorize('update', $transfer);

        return $this->respond(new StockTransferResource($this->transfers->dispatch($transfer, $request->boolean('allow_partial'))), 'Stock dispatched (earliest expiry first).');
    }

    public function receive(StockTransfer $transfer)
    {
        $this->authorize('update', $transfer);

        return $this->respond(new StockTransferResource($this->transfers->receive($transfer)), 'Stock received into '.$transfer->toStore->name.'.');
    }

    public function cancel(StockTransfer $transfer)
    {
        $this->authorize('update', $transfer);

        return $this->respond(new StockTransferResource($this->transfers->cancel($transfer)), 'Transfer cancelled.');
    }
}
