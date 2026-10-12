<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\MedicineBatch;
use App\Models\PharmacyMedicine;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Purchasing: reorder suggestions -> purchase order -> goods received (creates batches).
 */
class PurchaseService
{
    /** Order enough to cover this many times the reorder level. */
    public const TARGET_MULTIPLIER = 3;

    public function __construct(private StockService $stock, private ActivityLogger $logger) {}

    /**
     * Medicines at or below their reorder level, with a suggested quantity and the last purchase price.
     */
    public function suggestions(): array
    {
        return PharmacyMedicine::with('medicine')
            ->withAvailableQuantity()
            ->withMax('batches as last_cost', 'purchase_price')
            ->whereStock('<=', 'reorder_level')
            ->get()
            ->map(function ($listing) {
                $available = (int) $listing->available_quantity;
                $target = max($listing->reorder_level * self::TARGET_MULTIPLIER, $listing->reorder_level + 1);

                return [
                    'pharmacy_medicine_id' => $listing->id,
                    'name' => $listing->medicine->displayName(),
                    'unit' => $listing->medicine->unit,
                    'available' => $available,
                    'reorder_level' => $listing->reorder_level,
                    'suggested_quantity' => max(1, $target - $available),
                    'unit_cost' => round((float) ($listing->last_cost ?? $listing->price * 0.7), 2),
                ];
            })
            ->sortBy('available')
            ->values()
            ->all();
    }

    /** @param array<int, array{pharmacy_medicine_id: int, quantity: int, unit_cost: float}> $items */
    public function create(Supplier $supplier, array $items, array $attributes = []): PurchaseOrder
    {
        return DB::transaction(function () use ($supplier, $items, $attributes) {
            $po = PurchaseOrder::create($attributes + [
                'tenant_id' => $supplier->tenant_id,
                'po_number' => DocumentNumber::next($supplier->tenant_id, 'purchase_order'),
                'supplier_id' => $supplier->id,
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);
            foreach ($items as $item) {
                PharmacyMedicine::where('tenant_id', $supplier->tenant_id)->findOrFail($item['pharmacy_medicine_id']);
                $po->items()->create([
                    'pharmacy_medicine_id' => $item['pharmacy_medicine_id'],
                    'quantity_ordered' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                ]);
            }
            $po->update(['total' => round($po->items()->get()->sum(fn ($i) => $i->quantity_ordered * (float) $i->unit_cost), 2)]);
            $this->logger->log('purchase_order_created', $po, ['supplier' => $supplier->name, 'total' => $po->total], "PO {$po->po_number} to {$supplier->name}");

            return $po->load('items.listing.medicine', 'supplier');
        });
    }

    public function send(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw BusinessRuleException::withErrors('status', 'Only draft purchase orders can be sent.');
        }
        $po->update(['status' => 'sent', 'sent_at' => now()]);
        $this->logger->log('purchase_order_sent', $po);

        return $po;
    }

    /**
     * Goods received note: each line becomes a batch (number + expiry, often scanned from the GS1 DataMatrix).
     *
     * @param  array<int, array{item_id: int, quantity: int, batch_number: string, expiry_date: string, gtin?: ?string, unit_cost?: float}>  $lines
     */
    public function receive(PurchaseOrder $po, array $lines): PurchaseOrder
    {
        if (! in_array($po->status, ['sent', 'partially_received'], true)) {
            throw BusinessRuleException::withErrors('status', 'Send the purchase order before receiving goods.');
        }

        DB::transaction(function () use ($po, $lines) {
            $items = $po->items()->with('listing.medicine')->get()->keyBy('id');
            foreach ($lines as $i => $line) {
                $item = $items->get($line['item_id']);
                if (! $item) {
                    throw BusinessRuleException::withErrors("lines.{$i}.item_id", 'Line is not on this purchase order.');
                }
                if ($line['quantity'] > $item->outstanding()) {
                    throw BusinessRuleException::withErrors("lines.{$i}.quantity", "Only {$item->outstanding()} still outstanding for {$item->listing->medicine->displayName()}.");
                }
                $duplicate = MedicineBatch::withoutGlobalScopes()->where('pharmacy_medicine_id', $item->pharmacy_medicine_id)
                    ->where('store_id', $po->store_id ?? Store::defaultFor($po->tenant_id)->id)
                    ->where('batch_number', $line['batch_number'])->exists();
                if ($duplicate) {
                    throw BusinessRuleException::withErrors("lines.{$i}.batch_number", "Batch {$line['batch_number']} is already in stock for this medicine.");
                }

                $this->stock->receive($item->listing, [
                    'batch_number' => $line['batch_number'],
                    'quantity' => $line['quantity'],
                    'expiry_date' => $line['expiry_date'],
                    'purchase_price' => $line['unit_cost'] ?? $item->unit_cost,
                    'supplier' => $po->supplier->name,
                    'supplier_id' => $po->supplier_id,
                    'store_id' => $po->store_id,
                    'gtin' => $line['gtin'] ?? null,
                    'reason' => "Received on {$po->po_number}",
                ]);
                $item->increment('quantity_received', $line['quantity']);
            }

            $complete = $po->items()->get()->every(fn ($i) => $i->outstanding() === 0);
            $po->update(['status' => $complete ? 'received' : 'partially_received', 'received_at' => $complete ? now() : null]);
        });

        return $po->refresh()->load('items.listing.medicine', 'supplier');
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        if (! in_array($po->status, ['draft', 'sent'], true)) {
            throw BusinessRuleException::withErrors('status', 'Goods were already received on this order.');
        }
        $po->update(['status' => 'cancelled']);

        return $po;
    }
}
