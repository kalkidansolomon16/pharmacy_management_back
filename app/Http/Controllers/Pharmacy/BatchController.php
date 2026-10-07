<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\ReceiveBatchRequest;
use App\Http\Resources\MedicineBatchResource;
use App\Http\Resources\StockMovementResource;
use App\Models\MedicineBatch;
use App\Models\PharmacyMedicine;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function __construct(private StockService $stock) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PharmacyMedicine::class);

        $batches = MedicineBatch::query()
            ->with('pharmacyMedicine.medicine')
            ->when($request->query('listing_id'), fn ($q, $id) => $q->where('pharmacy_medicine_id', $id))
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('batch_number', 'like', "%{$term}%")
                ->orWhereHas('pharmacyMedicine.medicine', fn ($m) => $m->search($term))))
            ->when($request->query('status'), fn ($q, $status) => match ($status) {
                'expiring' => $q->expiringWithin((int) $request->query('days', InventoryService::EXPIRY_WARNING_DAYS)),
                'expired' => $q->expired(),
                'active' => $q->sellable(),
                'empty' => $q->where('quantity', 0),
                default => $q,
            })
            ->fefo()
            ->paginate($this->perPage(20));

        return MedicineBatchResource::collection($batches);
    }

    public function store(ReceiveBatchRequest $request, PharmacyMedicine $listing)
    {
        $this->authorize('manageStock', $listing);
        $batch = $this->stock->receive($listing, $request->validated());

        return $this->respond(new MedicineBatchResource($batch), "Received {$batch->quantity} units (batch {$batch->batch_number}).", 201);
    }

    public function adjust(AdjustStockRequest $request, MedicineBatch $batch)
    {
        $this->authorize('manageStock', $batch->pharmacyMedicine);
        $movement = $this->stock->adjust($batch, (int) $request->input('quantity'), $request->input('reason'));

        return $this->respond([
            'batch' => new MedicineBatchResource($batch->refresh()),
            'movement' => new StockMovementResource($movement),
        ], 'Stock adjusted.');
    }

    public function movements(Request $request)
    {
        $this->authorize('viewAny', PharmacyMedicine::class);

        $movements = StockMovement::query()
            ->with('batch.pharmacyMedicine.medicine', 'creator:id,name')
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('listing_id'), fn ($q, $id) => $q->whereHas('batch', fn ($b) => $b->where('pharmacy_medicine_id', $id)))
            ->when($request->query('from'), fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->query('to'), fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->latest('id')
            ->paginate($this->perPage(25));

        return StockMovementResource::collection($movements);
    }
}
