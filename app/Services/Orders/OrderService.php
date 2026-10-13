<?php

namespace App\Services\Orders;

use App\Exceptions\BusinessRuleException;
use App\Jobs\CheckLowStock;
use App\Models\Order;
use App\Models\PayerMembership;
use App\Models\PharmacyMedicine;
use App\Models\Prescription;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewOrderReceived;
use App\Notifications\OrderStatusChanged;
use App\Services\ActivityLogger;
use App\Services\Billing\BillingService;
use App\Services\Inventory\StockService;
use App\Services\Prescriptions\PrescriptionService;
use App\Support\ReferenceGenerator;
use App\Support\TaxCalculator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private StockService $stock,
        private PrescriptionService $prescriptions,
        private ActivityLogger $logger,
        private BillingService $billing,
    ) {}

    /**
     * A customer places an online order with one pharmacy (pickup or delivery).
     */
    public function placeOnline(User $customer, array $data): Order
    {
        $pharmacy = Tenant::pharmacies()->active()->find($data['pharmacy_id']);
        if (! $pharmacy) {
            throw BusinessRuleException::withErrors('pharmacy_id', 'This pharmacy is not accepting orders.');
        }

        if (($data['fulfillment'] ?? 'pickup') === 'delivery' && ! $pharmacy->delivery_available) {
            throw BusinessRuleException::withErrors('fulfillment', "{$pharmacy->name} does not offer delivery. Please choose pickup.");
        }

        $prescription = ! empty($data['prescription_code'])
            ? $this->prescriptions->findDispensable($data['prescription_code'], $data['patient_phone'] ?? $customer->phone)
            : null;

        $lines = $this->buildLines($pharmacy, $data['items'], $prescription, online: true);

        $order = DB::transaction(function () use ($customer, $pharmacy, $prescription, $lines, $data) {
            $order = $this->createOrder($pharmacy, $lines, [
                'user_id' => $customer->id,
                'prescription_id' => $prescription?->id,
                'channel' => 'online',
                'status' => 'pending',
                'customer_name' => $customer->name,
                'customer_phone' => ReferenceGenerator::normalizePhone($data['customer_phone'] ?? $customer->phone),
                'fulfillment' => $data['fulfillment'] ?? 'pickup',
                'delivery_address' => $data['delivery_address'] ?? null,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->logger->log('order_placed', $order, ['total' => $order->total_amount], "Online order {$order->order_number} placed by {$customer->name}");

            return $order;
        });

        $this->notifyPharmacy($order);

        return $order->load('items.medicine', 'pharmacy');
    }

    /**
     * Point-of-sale: a pharmacist sells over the counter. Created and dispensed in one transaction.
     */
    public function sellWalkIn(User $staff, array $data): Order
    {
        $pharmacy = $staff->tenant;

        // Offline sales are re-sent when the connection returns: the same device id is recorded only once
        if (! empty($data['client_uuid']) && ($existing = Order::withoutGlobalScopes()->where('client_uuid', $data['client_uuid'])->first())) {
            return $existing->fresh(['items.medicine', 'items.allocations.batch', 'creator']);
        }

        $storeId = ! empty($data['store_id']) ? Store::where('tenant_id', $pharmacy->id)->findOrFail($data['store_id'])->id : null;
        $membership = null;
        if (! empty($data['membership_id'])) {
            $membership = PayerMembership::with('payer')->where('tenant_id', $pharmacy->id)->findOrFail($data['membership_id']);
            if (! $membership->isValid()) {
                throw BusinessRuleException::withErrors('membership_id', 'This insurance / credit coverage is not active.');
            }
        }

        $prescription = ! empty($data['prescription_code'])
            ? $this->prescriptions->findDispensable($data['prescription_code'])
            : null;

        $lines = $this->buildLines($pharmacy, $data['items'], $prescription, online: false);

        // Pay at the cashier: the pharmacist only prepares the sale; the cashier takes the money, then the medicine is handed over.
        // Sales recorded while offline were already paid at the counter, so they complete as before.
        if ($pharmacy->setting('pay_at_cashier') && empty($data['recorded_offline'])) {
            $order = DB::transaction(fn () => $this->createOrder($pharmacy, $lines, [
                'created_by' => $staff->id,
                'prescription_id' => $prescription?->id,
                'channel' => 'walk_in',
                'status' => 'confirmed',
                'payment_status' => 'unpaid',
                'customer_name' => $data['customer_name'] ?? ($prescription?->patient->full_name ?? 'Walk-in customer'),
                'customer_phone' => ReferenceGenerator::normalizePhone($data['customer_phone'] ?? null),
                'payment_method' => $data['payment_method'] ?? 'cash',
                'discount' => $data['discount'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'store_id' => $storeId,
                'membership_id' => $membership?->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]));
            $this->logger->log('sale_sent_to_cashier', $order, ['total' => $order->total_amount], "Sale {$order->order_number} sent to the cashier");

            return $order->fresh(['items.medicine', 'creator']);
        }

        $order = DB::transaction(function () use ($staff, $pharmacy, $prescription, $lines, $data, $storeId, $membership) {
            $order = $this->createOrder($pharmacy, $lines, [
                'created_by' => $staff->id,
                'prescription_id' => $prescription?->id,
                'channel' => 'walk_in',
                'status' => 'confirmed',
                'customer_name' => $data['customer_name'] ?? ($prescription?->patient->full_name ?? 'Walk-in customer'),
                'customer_phone' => ReferenceGenerator::normalizePhone($data['customer_phone'] ?? null),
                'payment_method' => $data['payment_method'] ?? 'cash',
                'discount' => $data['discount'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'store_id' => $storeId,
                'membership_id' => $membership?->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            $this->dispense($order, allowPartial: false);
            $this->billing->invoiceForOrder($order->refresh());
            $this->logger->log('sale_completed', $order, ['total' => $order->total_amount], "Walk-in sale {$order->order_number}");

            return $order;
        });

        $this->checkStockLevels($order);

        return $order->fresh(['items.medicine', 'items.allocations.batch', 'creator']);
    }

    /** A counter sale waiting for the cashier (pay-at-cashier pharmacies). */
    public static function awaitsCashier(Order $order): bool
    {
        return $order->channel === 'walk_in' && $order->payment_status === 'unpaid' && $order->isOpen();
    }

    /**
     * Cashier takes the money for a counter sale: stock is deducted (FEFO), the prescription updated,
     * and the fiscal receipt issued with this payment. The pharmacist then hands the medicine over.
     */
    public function collectAtCashier(Order $order, string $method, ?string $reference = null): Order
    {
        DB::transaction(function () use ($order, $method, $reference) {
            $locked = Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! self::awaitsCashier($locked)) {
                throw BusinessRuleException::withErrors('status', "Sale {$locked->order_number} is not waiting for payment (it is {$locked->status}).");
            }

            $locked->update(['payment_method' => $method]);
            $this->dispense($locked, allowPartial: false); // walk-in sales become paid here
            $invoice = $this->billing->invoiceForOrder($locked->refresh());
            if ($reference) {
                $invoice->payments()->latest('id')->first()?->update(['reference' => $reference]);
            }
            $this->logger->log('sale_paid_at_cashier', $locked, ['total' => $locked->total_amount, 'method' => $method], "Sale {$locked->order_number} paid at the cashier");
        });

        $this->checkStockLevels($order);

        return $order->fresh(['items.medicine', 'creator', 'invoice']);
    }

    public function confirm(Order $order): Order
    {
        // Re-check stock before promising the customer anything
        $order->loadMissing('items.pharmacyMedicine.medicine');
        foreach ($order->items as $item) {
            $available = $item->pharmacyMedicine->availableQuantity();
            if ($available < $item->quantity) {
                throw new BusinessRuleException(
                    "Not enough stock to confirm {$item->medicine->generic_name}: {$available} available, {$item->quantity} ordered. You can still complete it partially.",
                    ['stock' => ['Insufficient stock']]
                );
            }
        }

        return $this->transition($order, 'confirmed');
    }

    public function markReady(Order $order): Order
    {
        return $this->transition($order, 'ready');
    }

    public function reject(Order $order, ?string $reason): Order
    {
        return $this->transition($order, 'rejected', ['cancel_reason' => $reason]);
    }

    public function cancel(Order $order, ?string $reason): Order
    {
        return $this->transition($order, 'cancelled', ['cancel_reason' => $reason]);
    }

    /**
     * Hand the medicines to the customer: deduct stock FEFO and update the prescription.
     */
    public function complete(Order $order, bool $allowPartial = false, ?string $paymentStatus = 'paid'): Order
    {
        DB::transaction(function () use ($order, $allowPartial, $paymentStatus) {
            $locked = Order::withoutGlobalScopes()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $locked->canTransitionTo('completed')) {
                throw BusinessRuleException::withErrors('status', "An order that is {$locked->status} cannot be completed.");
            }
            if (self::awaitsCashier($locked)) {
                throw BusinessRuleException::withErrors('payment', "Sale {$locked->order_number} is waiting for payment at the cashier.");
            }

            $this->dispense($locked, $allowPartial);
            if ($paymentStatus) {
                $locked->update(['payment_status' => $paymentStatus]);
            }
            $this->billing->invoiceForOrder($locked->refresh());
            $this->logger->log('order_'.$locked->status, $locked, [
                'total' => $locked->total_amount,
                'allow_partial' => $allowPartial,
            ]);
        });

        $order->refresh();
        $order->customer?->notify(new OrderStatusChanged($order));
        $this->checkStockLevels($order);

        return $order->load('items.medicine', 'items.allocations.batch');
    }

    private function checkStockLevels(Order $order): void
    {
        $sold = $order->items()->pluck('fulfilled_quantity', 'pharmacy_medicine_id')->map(fn ($q) => (int) $q)->all();
        CheckLowStock::dispatch($order->tenant_id, $sold);
    }

    /**
     * Core dispensing routine. Caller owns the transaction.
     */
    private function dispense(Order $order, bool $allowPartial): void
    {
        $prescription = $order->prescription_id
            ? Prescription::acrossTenants()->whereKey($order->prescription_id)->lockForUpdate()->first()
            : null;

        if ($prescription) {
            $this->prescriptions->assertDispensable($prescription);
        }

        $order->loadMissing('items.pharmacyMedicine.medicine', 'items.prescriptionItem');
        $everythingFulfilled = true;

        foreach ($order->items as $item) {
            $wanted = $item->quantity;

            if ($item->prescriptionItem) {
                $remaining = $item->prescriptionItem->fresh()->remainingQuantity();
                if ($remaining < $wanted && ! $allowPartial) {
                    throw BusinessRuleException::withErrors('quantity', "Prescription allows only {$remaining} more units of {$item->medicine->generic_name}.");
                }
                $wanted = min($wanted, $remaining);
            }

            $allocations = $wanted > 0 ? $this->stock->allocate($item->pharmacyMedicine, $wanted, $allowPartial, $order->store_id) : [];
            $fulfilled = $this->stock->deduct($item, $allocations, $order);

            if ($item->prescriptionItem && $fulfilled > 0) {
                $this->prescriptions->recordDispense($item->prescriptionItem, $fulfilled);
            }

            $item->update([
                'fulfilled_quantity' => $fulfilled,
                'line_total' => round($fulfilled * (float) $item->unit_price, 2),
            ]);

            $everythingFulfilled = $everythingFulfilled && $fulfilled === $item->quantity;
        }

        $subtotal = (float) $order->items->sum('line_total');
        if ($subtotal <= 0) {
            throw BusinessRuleException::withErrors('stock', 'Nothing could be dispensed: no sellable stock is left for these items.');
        }

        $tax = $this->taxFor($order->pharmacy()->first(), $order->items->map(fn ($i) => [(float) $i->line_total, $i->pharmacyMedicine->medicine->tax_category ?? 'exempt']));

        $order->update([
            'status' => $everythingFulfilled ? 'completed' : 'partially_completed',
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'total_amount' => max(0, $subtotal + $tax - (float) $order->discount),
            'fulfilled_at' => now(),
            'payment_status' => $order->channel === 'walk_in' ? 'paid' : $order->payment_status,
        ]);

        if ($prescription) {
            $this->prescriptions->refreshStatus($prescription);
        }
    }

    private function transition(Order $order, string $status, array $extra = []): Order
    {
        if (! $order->canTransitionTo($status)) {
            throw BusinessRuleException::withErrors('status', "Cannot move an order from {$order->status} to {$status}.");
        }

        $from = $order->status;
        $order->update(['status' => $status] + $extra);
        $this->logger->log('order_'.$status, $order, ['from' => $from, 'to' => $status] + $extra);
        $order->customer?->notify(new OrderStatusChanged($order));

        return $order;
    }

    /**
     * Validate requested items against the pharmacy's listings, stock and the prescription.
     * Collects every problem so the customer can fix the whole cart at once.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildLines(Tenant $pharmacy, array $items, ?Prescription $prescription, bool $online): array
    {
        $requested = collect($items)
            ->groupBy('pharmacy_medicine_id')
            ->map(fn ($rows) => (int) $rows->sum('quantity'));

        $listings = PharmacyMedicine::acrossTenants()
            ->with('medicine')
            ->withAvailableQuantity()
            ->where('tenant_id', $pharmacy->id)
            ->whereIn('id', $requested->keys())
            ->get()
            ->keyBy('id');

        $rxItems = $prescription?->items->keyBy('medicine_id') ?? collect();
        $errors = [];
        $lines = [];

        foreach ($requested as $listingId => $quantity) {
            $key = "items.{$listingId}";
            $listing = $listings->get($listingId);

            if (! $listing || ! $listing->medicine->is_active || ($online && ! $listing->is_public)) {
                $errors[$key][] = 'This medicine is not available at '.$pharmacy->name.'.';

                continue;
            }

            $medicine = $listing->medicine;
            $name = $medicine->displayName();
            $available = (int) $listing->available_quantity;

            if ($quantity > $available) {
                $errors[$key][] = $available > 0
                    ? "Only {$available} {$medicine->unit}(s) of {$name} are in stock."
                    : "{$name} is out of stock.";
            }

            if ($online && $medicine->is_controlled) {
                $errors[$key][] = "{$name} is a controlled medicine and can only be dispensed in person with the original prescription.";
            }

            $rxItem = $rxItems->get($medicine->id);
            $needsPrescription = $medicine->prescription_required || $medicine->is_controlled;

            if ($needsPrescription && ! $prescription) {
                $errors[$key][] = "{$name} requires a valid prescription. Enter the prescription reference code.";
            } elseif ($needsPrescription && ! $rxItem) {
                $errors[$key][] = "Prescription {$prescription->reference_code} does not include {$name}.";
            }

            if ($rxItem && $quantity > $rxItem->remainingQuantity()) {
                $errors[$key][] = "The prescription allows only {$rxItem->remainingQuantity()} more {$medicine->unit}(s) of {$name}.";
            }

            $lines[] = [
                'listing' => $listing,
                'quantity' => $quantity,
                'prescription_item_id' => $rxItem?->id,
            ];
        }

        if ($errors) {
            throw new BusinessRuleException('Some items in this order cannot be fulfilled.', $errors);
        }

        return $lines;
    }

    private function createOrder(Tenant $pharmacy, array $lines, array $attributes): Order
    {
        $subtotal = collect($lines)->sum(fn ($l) => $l['quantity'] * (float) $l['listing']->price);
        $discount = min((float) Arr::get($attributes, 'discount', 0), $subtotal);

        $tax = $this->taxFor($pharmacy, collect($lines)->map(fn ($l) => [$l['quantity'] * (float) $l['listing']->price, $l['listing']->medicine->tax_category ?? 'exempt']));

        $order = Order::create($attributes + [
            'order_number' => ReferenceGenerator::orderNumber(),
            'tenant_id' => $pharmacy->id,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax_total' => $tax,
            'total_amount' => $subtotal + $tax - $discount,
        ]);

        foreach ($lines as $line) {
            $order->items()->create([
                'pharmacy_medicine_id' => $line['listing']->id,
                'medicine_id' => $line['listing']->medicine_id,
                'prescription_item_id' => $line['prescription_item_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['listing']->price,
                'line_total' => round($line['quantity'] * (float) $line['listing']->price, 2),
            ]);
        }

        return $order;
    }

    /** VAT / TOT on sale lines given as [net amount, tax category] pairs. */
    private function taxFor(Tenant $pharmacy, \Illuminate\Support\Collection $lines): float
    {
        return round($lines->sum(fn ($l) => TaxCalculator::line($pharmacy, (float) $l[0], $l[1])['tax']), 2);
    }

    private function notifyPharmacy(Order $order): void
    {
        User::where('tenant_id', $order->tenant_id)
            ->where('status', 'active')
            ->permission('orders.view') // anyone who handles orders, whatever their role
            ->get()
            ->each->notify(new NewOrderReceived($order));
    }
}
