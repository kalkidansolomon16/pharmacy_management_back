<?php

namespace App\Services\Inventory;

use App\Models\MedicineBatch;
use App\Models\PharmacyMedicine;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public const EXPIRY_WARNING_DAYS = 90;

    public function __construct(private StockService $stock) {}

    /**
     * List a catalogue medicine in the pharmacy, optionally receiving its opening batch at the same time.
     */
    public function addListing(array $data): PharmacyMedicine
    {
        return DB::transaction(function () use ($data) {
            $listing = PharmacyMedicine::create([
                'medicine_id' => $data['medicine_id'],
                'price' => $data['price'],
                'reorder_level' => $data['reorder_level'] ?? 20,
                'is_public' => $data['is_public'] ?? true,
            ]);

            if (! empty($data['batch'])) {
                $this->stock->receive($listing, $data['batch']);
            }

            return $listing;
        });
    }

    /**
     * What needs the pharmacist's attention today.
     */
    public function alerts(int $limit = 10): array
    {
        $low = PharmacyMedicine::with('medicine')
            ->withAvailableQuantity()
            ->whereStock('<=', 'reorder_level')
            ->orderBy('available_quantity')
            ->limit($limit)
            ->get();

        $expiring = MedicineBatch::with('pharmacyMedicine.medicine')
            ->expiringWithin(self::EXPIRY_WARNING_DAYS)
            ->fefo()
            ->limit($limit)
            ->get();

        return [
            'low_stock_count' => PharmacyMedicine::whereStock('<=', 'reorder_level')->count(),
            'out_of_stock_count' => PharmacyMedicine::whereStock('=', 0)->count(),
            'expiring_count' => MedicineBatch::expiringWithin(self::EXPIRY_WARNING_DAYS)->count(),
            'expired_with_stock_count' => MedicineBatch::expired()->where('quantity', '>', 0)->count(),
            'low_stock' => $low,
            'expiring' => $expiring,
        ];
    }
}
