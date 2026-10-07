<?php

namespace App\Services\Orders;

use App\Exceptions\BusinessRuleException;
use App\Jobs\CheckLowStock;
use App\Models\Order;
use App\Models\PharmacyMedicine;
use App\Models\Prescription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewOrderReceived;
use App\Notifications\OrderStatusChanged;
use App\Services\ActivityLogger;
use App\Services\Inventory\StockService;
use App\Services\Prescriptions\PrescriptionService;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private StockService $stock,
        private PrescriptionService $prescriptions,
        private ActivityLogger $logger,
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

        $prescription = ! empty($data['prescription_code'])
            ? $this->prescriptions->findDispensable($data['prescription_code'])
            : null;

        $lines = $this->buildLines($pharmacy, $data['items'], $prescription, online: false);

        $order = DB::transaction(function () use ($staff, $pharmacy, $prescription, $lines, $data) {
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
            ]);

            $this->dispense($order, allowPartial: false);
            $this->logger->log('sale_completed', $order, ['total' => $order->total_amount], "Walk-in sale {$order->order_number}");

            return $order;
        });

        $this->checkStockLevels($order);

        return $order->fresh(['items.medicine', 'items.allocations.batch', 'creator']);
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

            $this->dispense($locked, $allowPartial);
            if ($paymentStatus) {
                $locked->update(['payment_status' => $paymentStatus]);
            }
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

            $allocations = $wanted > 0 ? $this->stock->allocate($item->pharmacyMedicine, $wanted, $allowPartial) : [];
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

        $order->update([
            'status' => $everythingFulfilled ? 'completed' : 'partially_completed',
            'subtotal' => $subtotal,
            'total_amount' => max(0, $subtotal - (float) $order->discount),
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

        $order = Order::create($attributes + [
            'order_number' => ReferenceGenerator::orderNumber(),
            'tenant_id' => $pharmacy->id,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total_amount' => $subtotal - $discount,
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

    private function notifyPharmacy(Order $order): void
    {
        User::where('tenant_id', $order->tenant_id)
            ->where('status', 'active')
            ->role([User::ROLE_PHARMACY_ADMIN, User::ROLE_STAFF])
            ->get()
            ->each->notify(new NewOrderReceived($order));
    }
}
