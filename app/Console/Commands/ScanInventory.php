<?php

namespace App\Console\Commands;

use App\Jobs\ScanPharmacyInventory;
use App\Models\Tenant;
use App\Services\Prescriptions\PrescriptionService;
use Illuminate\Console\Command;

class ScanInventory extends Command
{
    protected $signature = 'pharmacy:scan {--tenant= : Only scan one pharmacy} {--sync : Run immediately instead of queueing}';

    protected $description = 'Expire overdue batches & prescriptions and send low-stock / expiry alerts';

    public function handle(PrescriptionService $prescriptions): int
    {
        $expiredRx = $prescriptions->expireOverdue();
        $this->info("Prescriptions marked expired: {$expiredRx}");

        $pharmacies = Tenant::pharmacies()->active()
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))
            ->pluck('id');

        foreach ($pharmacies as $id) {
            $this->option('sync') ? ScanPharmacyInventory::dispatchSync($id) : ScanPharmacyInventory::dispatch($id);
        }

        $this->info(($this->option('sync') ? 'Scanned' : 'Queued scans for')." {$pharmacies->count()} pharmacies.");

        return self::SUCCESS;
    }
}
