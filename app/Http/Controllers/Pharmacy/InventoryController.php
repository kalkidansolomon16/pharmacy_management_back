<?php

namespace App\Http\Controllers\Pharmacy;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\SaveListingRequest;
use App\Http\Resources\MedicineBatchResource;
use App\Http\Resources\PharmacyMedicineResource;
use App\Jobs\ScanPharmacyInventory;
use App\Models\PharmacyMedicine;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PharmacyMedicine::class);

        // select() must come before the aggregates, or it would replace their columns
        $listings = PharmacyMedicine::query()
            ->select('pharmacy_medicines.*')
            ->with('medicine.category')
            ->withAvailableQuantity()
            ->withNearestExpiry()
            ->whereHas('medicine', fn ($m) => $m->search($request->query('search'))
                ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id)))
            ->when($request->query('filter'), fn ($q, $filter) => match ($filter) {
                'low_stock' => $q->whereStock('<=', 'reorder_level')->whereStock('>', 0),
                'out_of_stock' => $q->whereStock('=', 0),
                'in_stock' => $q->whereStock('>', 'reorder_level'),
                'expiring' => $q->whereHas('batches', fn ($b) => $b->expiringWithin(InventoryService::EXPIRY_WARNING_DAYS)),
                'hidden' => $q->where('is_public', false),
                default => $q,
            })
            ->join('medicines', 'medicines.id', '=', 'pharmacy_medicines.medicine_id')
            ->orderBy(match ($request->query('sort')) {
                'price' => 'pharmacy_medicines.price',
                'updated' => 'pharmacy_medicines.updated_at',
                default => 'medicines.generic_name',
            }, $request->query('direction') === 'desc' ? 'desc' : 'asc')
            ->paginate($this->perPage(20));

        return PharmacyMedicineResource::collection($listings);
    }

    public function store(SaveListingRequest $request)
    {
        $this->authorize('create', PharmacyMedicine::class);
        $listing = $this->inventory->addListing($request->validated());

        return $this->respond(
            new PharmacyMedicineResource($listing->load('medicine')->loadSum(['batches as available_quantity' => fn ($q) => $q->sellable()], 'quantity')),
            'Medicine added to your inventory.',
            201
        );
    }

    public function show(PharmacyMedicine $listing)
    {
        $this->authorize('view', $listing);

        $listing->load(['medicine.category', 'batches' => fn ($q) => $q->orderByDesc('quantity')->orderBy('expiry_date')])
            ->loadSum(['batches as available_quantity' => fn ($q) => $q->sellable()], 'quantity');

        return new PharmacyMedicineResource($listing);
    }

    public function update(SaveListingRequest $request, PharmacyMedicine $listing)
    {
        $this->authorize('update', $listing);
        $listing->update($request->validated());

        return $this->respond(new PharmacyMedicineResource($listing->load('medicine')), 'Listing updated.');
    }

    public function destroy(PharmacyMedicine $listing)
    {
        $this->authorize('delete', $listing);

        if ($listing->availableQuantity() > 0) {
            $listing->update(['is_public' => false]);

            return $this->respond(new PharmacyMedicineResource($listing), 'This medicine still has stock, so it was hidden from customers instead of removed.');
        }

        if ($listing->batches()->exists()) {
            $listing->update(['is_public' => false]);

            return $this->respond(new PharmacyMedicineResource($listing), 'Stock history exists, so the listing was hidden instead of removed.');
        }

        $listing->delete();

        return $this->respond(null, 'Medicine removed from your inventory.');
    }

    public function alerts()
    {
        $this->authorize('viewAny', PharmacyMedicine::class);
        $alerts = $this->inventory->alerts();

        return $this->respond([
            ...$alerts,
            'low_stock' => PharmacyMedicineResource::collection($alerts['low_stock'])->resolve(),
            'expiring' => MedicineBatchResource::collection($alerts['expiring'])->resolve(),
        ]);
    }

    /** Run the daily scan now (expire batches, send alerts). */
    public function scan(Request $request)
    {
        $this->authorize('create', PharmacyMedicine::class);
        ScanPharmacyInventory::dispatchSync($request->user()->tenant_id);

        return $this->respond($this->inventory->alerts(0), 'Inventory scan complete. Expired batches were written off and alerts sent.');
    }
}
