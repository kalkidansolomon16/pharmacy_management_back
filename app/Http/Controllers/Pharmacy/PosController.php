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

        return $this->respond(new OrderResource($order), "Sale {$order->order_number} completed - ETB ".number_format((float) $order->total_amount, 2), 201);
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
