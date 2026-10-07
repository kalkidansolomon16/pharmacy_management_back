<?php

namespace App\Jobs;

use App\Models\PharmacyMedicine;
use App\Models\User;
use App\Notifications\LowStockAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Alert the pharmacy team about low stock.
 *
 * After a sale, pass [listing_id => quantity sold]: only medicines that this sale pushed
 * across their reorder level are reported, so the team is not alerted on every sale.
 * Without quantities (daily scan) every low item is reported.
 */
class CheckLowStock implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, int>  $soldQuantities
     */
    public function __construct(public int $tenantId, public array $soldQuantities = [])
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $low = PharmacyMedicine::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->when($this->soldQuantities, fn ($q) => $q->whereIn('id', array_keys($this->soldQuantities)))
            ->with('medicine')
            ->withAvailableQuantity()
            ->get()
            ->filter(function (PharmacyMedicine $listing) {
                $available = (int) $listing->available_quantity;
                if ($available > $listing->reorder_level) {
                    return false;
                }
                if (! $this->soldQuantities) {
                    return true;
                }

                // Was above the threshold before this sale?
                return $available + ($this->soldQuantities[$listing->id] ?? 0) > $listing->reorder_level;
            })
            ->map(fn ($l) => [
                'name' => $l->medicine->displayName(),
                'available' => (int) $l->available_quantity,
                'reorder_level' => $l->reorder_level,
            ])
            ->values()
            ->all();

        if (! $low) {
            return;
        }

        User::where('tenant_id', $this->tenantId)
            ->where('status', 'active')
            ->role([User::ROLE_PHARMACY_ADMIN, User::ROLE_STAFF])
            ->get()
            ->each->notify(new LowStockAlert($low));
    }
}
