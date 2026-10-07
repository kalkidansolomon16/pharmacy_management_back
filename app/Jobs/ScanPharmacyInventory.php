<?php

namespace App\Jobs;

use App\Models\MedicineBatch;
use App\Models\User;
use App\Notifications\ExpiryAlert;
use App\Services\Inventory\StockService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Daily per-pharmacy housekeeping: write off expired batches, warn about upcoming expiries
 * and low stock.
 */
class ScanPharmacyInventory implements ShouldQueue
{
    use Queueable;

    public const EXPIRY_WARNING_DAYS = 90;

    public function __construct(public int $tenantId) {}

    public function handle(StockService $stock): void
    {
        $expired = $stock->expireDueBatches($this->tenantId);

        $expiring = MedicineBatch::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->expiringWithin(self::EXPIRY_WARNING_DAYS)
            ->with('pharmacyMedicine.medicine')
            ->fefo()
            ->get()
            ->map(fn ($b) => [
                'name' => $b->pharmacyMedicine->medicine->displayName(),
                'batch' => $b->batch_number,
                'expiry_date' => $b->expiry_date->toDateString(),
                'quantity' => $b->quantity,
            ])
            ->all();

        if ($expired || $expiring) {
            User::where('tenant_id', $this->tenantId)
                ->where('status', 'active')
                ->role([User::ROLE_PHARMACY_ADMIN, User::ROLE_STAFF])
                ->get()
                ->each->notify(new ExpiryAlert($expiring, $expired));
        }

        CheckLowStock::dispatchSync($this->tenantId);
    }
}
