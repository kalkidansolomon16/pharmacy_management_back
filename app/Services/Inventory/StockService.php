<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\MedicineBatch;
use App\Models\OrderItem;
use App\Models\PharmacyMedicine;
use App\Models\StockMovement;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function __construct(private ActivityLogger $logger) {}

    /**
     * Receive a new batch from a supplier (stock in).
     */
    public function receive(PharmacyMedicine $listing, array $data): MedicineBatch
    {
        if (now()->startOfDay()->gte($data['expiry_date'])) {
            throw BusinessRuleException::withErrors('expiry_date', 'Cannot receive a batch that is already expired.');
        }

        return DB::transaction(function () use ($listing, $data) {
            $batch = MedicineBatch::create([
                'tenant_id' => $listing->tenant_id,
                'pharmacy_medicine_id' => $listing->id,
                'batch_number' => $data['batch_number'],
                'initial_quantity' => $data['quantity'],
                'quantity' => $data['quantity'],
                'expiry_date' => $data['expiry_date'],
                'purchase_price' => $data['purchase_price'],
                'supplier' => $data['supplier'] ?? null,
                'received_at' => $data['received_at'] ?? today(),
            ]);

            $this->record($batch, 'in', $batch->quantity, $data['reason'] ?? 'Stock received'.($batch->supplier ? " from {$batch->supplier}" : ''));
            $this->logger->log('stock_received', $listing, [
                'batch' => $batch->batch_number,
                'quantity' => $batch->quantity,
                'expiry_date' => $batch->expiry_date->toDateString(),
            ]);

            return $batch;
        });
    }

    /**
     * Manual correction (damage, count variance, return to supplier...). Delta may be negative.
     */
    public function adjust(MedicineBatch $batch, int $delta, string $reason): StockMovement
    {
        if ($delta === 0) {
            throw BusinessRuleException::withErrors('quantity', 'Adjustment quantity cannot be zero.');
        }

        return DB::transaction(function () use ($batch, $delta, $reason) {
            $locked = MedicineBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->quantity + $delta < 0) {
                throw BusinessRuleException::withErrors('quantity', "Batch {$locked->batch_number} only has {$locked->quantity} units.");
            }

            $locked->increment('quantity', $delta);
            $movement = $this->record($locked, 'adjustment', $delta, $reason);
            $this->logger->log('stock_adjusted', $locked->pharmacyMedicine, [
                'batch' => $locked->batch_number,
                'delta' => $delta,
                'reason' => $reason,
            ]);

            return $movement;
        });
    }

    /**
     * Pick batches First-Expiry-First-Out. Must run inside a transaction: rows are locked.
     * Expired batches are never selected, so expired medicine can never be sold.
     *
     * @return array<int, array{batch: MedicineBatch, quantity: int}>
     */
    public function allocate(PharmacyMedicine $listing, int $quantity, bool $allowPartial = false): array
    {
        $batches = MedicineBatch::withoutGlobalScopes()
            ->where('pharmacy_medicine_id', $listing->id)
            ->sellable()
            ->fefo()
            ->lockForUpdate()
            ->get();

        $available = (int) $batches->sum('quantity');
        if ($available < $quantity && ! $allowPartial) {
            $name = $listing->medicine?->displayName() ?? 'this medicine';
            throw new BusinessRuleException(
                "Insufficient stock for {$name}: requested {$quantity}, only {$available} available (non-expired).",
                ['stock' => ["Only {$available} units of {$name} are available."]]
            );
        }

        $allocations = [];
        $remaining = $quantity;
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($batch->quantity, $remaining);
            $allocations[] = ['batch' => $batch, 'quantity' => $take];
            $remaining -= $take;
        }

        return $allocations;
    }

    /**
     * Apply allocations to an order line: decrement batches, write "out" movements, keep the trail.
     */
    public function deduct(OrderItem $item, array $allocations, Model $reference): int
    {
        $total = 0;
        foreach ($allocations as ['batch' => $batch, 'quantity' => $qty]) {
            $batch->decrement('quantity', $qty);
            $item->allocations()->create(['batch_id' => $batch->id, 'quantity' => $qty]);
            $this->record($batch, 'out', -$qty, 'Dispensed', $reference);
            $total += $qty;
        }

        return $total;
    }

    /**
     * Write off every batch whose expiry date has passed. Returns the number of batches expired.
     */
    public function expireDueBatches(?int $tenantId = null): int
    {
        $count = 0;

        MedicineBatch::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('quantity', '>', 0)
            ->expired()
            ->each(function (MedicineBatch $batch) use (&$count) {
                DB::transaction(function () use ($batch) {
                    $qty = $batch->quantity;
                    $batch->update(['quantity' => 0]);
                    $this->record($batch, 'expired', -$qty, 'Expired on '.$batch->expiry_date->toDateString().' - removed from sellable stock');
                });
                $count++;
            });

        return $count;
    }

    private function record(MedicineBatch $batch, string $type, int $quantity, ?string $reason = null, ?Model $reference = null): StockMovement
    {
        return StockMovement::create([
            'tenant_id' => $batch->tenant_id,
            'batch_id' => $batch->id,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
            'reference_type' => $reference ? $reference->getMorphClass() : null,
            'reference_id' => $reference?->getKey(),
            'created_by' => Auth::id(),
        ]);
    }
}
