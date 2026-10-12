<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\MedicineBatch;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Services\ActivityLogger;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Returning stock to the supplier (near-expiry, damaged, recalled...) instead of writing it off.
 */
class SupplierReturnService
{
    public function __construct(private StockService $stock, private ActivityLogger $logger) {}

    public function create(MedicineBatch $batch, int $quantity, string $reason, ?Supplier $supplier, ?string $notes = null): SupplierReturn
    {
        return DB::transaction(function () use ($batch, $quantity, $reason, $supplier, $notes) {
            $locked = MedicineBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($quantity < 1 || $quantity > $locked->quantity) {
                throw BusinessRuleException::withErrors('quantity', "Batch {$locked->batch_number} has {$locked->quantity} units on hand.");
            }

            $locked->decrement('quantity', $quantity);
            $return = SupplierReturn::create([
                'tenant_id' => $locked->tenant_id,
                'return_number' => DocumentNumber::next($locked->tenant_id, 'supplier_return'),
                'supplier_id' => $supplier?->id ?? $locked->supplier_id,
                'batch_id' => $locked->id,
                'quantity' => $quantity,
                'reason' => $reason,
                'value' => round($quantity * (float) $locked->purchase_price, 2),
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);
            $this->stock->record($locked, 'return', -$quantity, "Returned to supplier ({$reason}) - {$return->return_number}", $return);
            $this->logger->log('supplier_return', $locked->pharmacyMedicine, ['batch' => $locked->batch_number, 'quantity' => $quantity, 'reason' => $reason]);

            return $return->load('batch.pharmacyMedicine.medicine', 'supplier');
        });
    }

    public function resolve(SupplierReturn $return, string $status, ?string $creditNote): SupplierReturn
    {
        if ($return->status !== 'pending') {
            throw BusinessRuleException::withErrors('status', 'This return was already resolved.');
        }
        $return->update(['status' => $status, 'credit_note_number' => $creditNote]);

        return $return;
    }
}
