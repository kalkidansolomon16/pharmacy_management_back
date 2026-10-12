<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\MedicineBatch;
use App\Models\PharmacyMedicine;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Services\ActivityLogger;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Moving stock between stores: main store -> OPD / ward / emergency dispensaries.
 * Request -> dispatch (stock leaves the source, FEFO) -> receive (stock arrives with the same batch & expiry).
 */
class TransferService
{
    public function __construct(private StockService $stock, private ActivityLogger $logger) {}

    /** @param array<int, array{pharmacy_medicine_id: int, quantity: int}> $items */
    public function request(Store $from, Store $to, array $items, ?string $notes = null): StockTransfer
    {
        if ($from->id === $to->id) {
            throw BusinessRuleException::withErrors('to_store_id', 'Choose two different stores.');
        }

        return DB::transaction(function () use ($from, $to, $items, $notes) {
            $transfer = StockTransfer::create([
                'tenant_id' => $from->tenant_id,
                'transfer_number' => DocumentNumber::next($from->tenant_id, 'transfer'),
                'from_store_id' => $from->id,
                'to_store_id' => $to->id,
                'status' => 'requested',
                'notes' => $notes,
                'requested_by' => Auth::id(),
            ]);
            foreach ($items as $item) {
                PharmacyMedicine::where('tenant_id', $from->tenant_id)->findOrFail($item['pharmacy_medicine_id']);
                $transfer->items()->create(['pharmacy_medicine_id' => $item['pharmacy_medicine_id'], 'quantity' => $item['quantity']]);
            }
            $this->logger->log('transfer_requested', $transfer, [], "Transfer {$transfer->transfer_number}: {$from->name} → {$to->name}");

            return $transfer->load('items.listing.medicine', 'fromStore', 'toStore');
        });
    }

    /** Pick stock from the source store (earliest expiry first) and take it off its shelves. */
    public function dispatch(StockTransfer $transfer, bool $allowPartial = false): StockTransfer
    {
        $this->expectStatus($transfer, 'requested');

        DB::transaction(function () use ($transfer, $allowPartial) {
            foreach ($transfer->items()->with('listing.medicine')->get() as $item) {
                $allocations = $this->stock->allocate($item->listing, $item->quantity, $allowPartial, $transfer->from_store_id);
                $sent = [];
                foreach ($allocations as ['batch' => $batch, 'quantity' => $qty]) {
                    $batch->decrement('quantity', $qty);
                    $this->stock->record($batch, 'transfer_out', -$qty, "Transfer {$transfer->transfer_number} to {$transfer->toStore->name}", $transfer);
                    $sent[] = [
                        'batch_id' => $batch->id, 'batch_number' => $batch->batch_number, 'gtin' => $batch->gtin,
                        'expiry_date' => $batch->expiry_date->toDateString(), 'purchase_price' => (float) $batch->purchase_price,
                        'supplier' => $batch->supplier, 'supplier_id' => $batch->supplier_id, 'quantity' => $qty,
                    ];
                }
                $item->update(['allocations' => $sent, 'dispatched_quantity' => array_sum(array_column($sent, 'quantity'))]);
            }

            if ((int) $transfer->items()->sum('dispatched_quantity') === 0) {
                throw BusinessRuleException::withErrors('stock', 'Nothing could be dispatched: the source store has no sellable stock for these items.');
            }
            $transfer->update(['status' => 'dispatched', 'dispatched_by' => Auth::id(), 'dispatched_at' => now()]);
            $this->logger->log('transfer_dispatched', $transfer);
        });

        return $transfer->refresh()->load('items.listing.medicine', 'fromStore', 'toStore');
    }

    /** Put the dispatched batches on the destination store's shelves (same batch number and expiry). */
    public function receive(StockTransfer $transfer): StockTransfer
    {
        $this->expectStatus($transfer, 'dispatched');

        DB::transaction(function () use ($transfer) {
            foreach ($transfer->items as $item) {
                foreach ($item->allocations ?? [] as $a) {
                    $batch = MedicineBatch::withoutGlobalScopes()->firstOrNew([
                        'pharmacy_medicine_id' => $item->pharmacy_medicine_id,
                        'store_id' => $transfer->to_store_id,
                        'batch_number' => $a['batch_number'],
                    ], [
                        'tenant_id' => $transfer->tenant_id, 'expiry_date' => $a['expiry_date'], 'purchase_price' => $a['purchase_price'],
                        'supplier' => $a['supplier'], 'supplier_id' => $a['supplier_id'], 'gtin' => $a['gtin'] ?? null,
                        'initial_quantity' => 0, 'quantity' => 0, 'received_at' => today(),
                    ]);
                    $batch->initial_quantity += $a['quantity'];
                    $batch->quantity += $a['quantity'];
                    $batch->save();
                    $this->stock->record($batch, 'transfer_in', $a['quantity'], "Transfer {$transfer->transfer_number} from {$transfer->fromStore->name}", $transfer);
                }
            }
            $transfer->update(['status' => 'received', 'received_by' => Auth::id(), 'received_at' => now()]);
            $this->logger->log('transfer_received', $transfer);
        });

        return $transfer->refresh()->load('items.listing.medicine', 'fromStore', 'toStore');
    }

    public function cancel(StockTransfer $transfer): StockTransfer
    {
        $this->expectStatus($transfer, 'requested');
        $transfer->update(['status' => 'cancelled']);

        return $transfer;
    }

    private function expectStatus(StockTransfer $transfer, string $status): void
    {
        if ($transfer->status !== $status) {
            throw BusinessRuleException::withErrors('status', "Transfer {$transfer->transfer_number} is {$transfer->status}.");
        }
    }
}
