<?php

namespace App\Services\Inventory;

use App\Models\Order;
use App\Models\PharmacyMedicine;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;

/**
 * Controlled-drug (narcotic & psychotropic) register: every unit in and out with a running balance,
 * and for each dispense the prescription, prescriber and patient - the book inspectors ask to see.
 */
class ControlledRegisterService
{
    public function controlledListings()
    {
        return PharmacyMedicine::with('medicine')
            ->withAvailableQuantity()
            ->whereHas('medicine', fn ($m) => $m->where('is_controlled', true))
            ->get();
    }

    public function ledger(PharmacyMedicine $listing, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $batchIds = $listing->batches()->pluck('id');
        $base = StockMovement::whereIn('batch_id', $batchIds);

        $opening = (int) (clone $base)->where('created_at', '<', $from->startOfDay())->sum('quantity');

        $movements = (clone $base)
            ->with('batch:id,batch_number,expiry_date', 'creator:id,name')
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $orders = Order::withoutGlobalScopes()
            ->with('prescription.doctor:id,name,license_number', 'prescription.patient:id,full_name,mrn', 'prescription.hospital:id,name')
            ->whereIn('id', $movements->where('reference_type', (new Order)->getMorphClass())->pluck('reference_id'))
            ->get()
            ->keyBy('id');

        $balance = $opening;
        $entries = $movements->map(function ($m) use (&$balance, $orders) {
            $balance += $m->quantity;
            $order = $m->reference_type === (new Order)->getMorphClass() ? $orders->get($m->reference_id) : null;
            $rx = $order?->prescription;

            return [
                'date' => $m->created_at->toIso8601String(),
                'type' => $m->type,
                'received' => $m->quantity > 0 ? $m->quantity : 0,
                'issued' => $m->quantity < 0 ? -$m->quantity : 0,
                'balance' => $balance,
                'batch' => $m->batch?->batch_number,
                'reference' => $order?->order_number,
                'prescription' => $rx?->reference_code,
                'patient' => $rx?->patient?->full_name ?? $order?->customer_name,
                'patient_mrn' => $rx?->patient?->mrn,
                'prescriber' => $rx?->doctor ? trim($rx->doctor->name.' '.($rx->doctor->license_number ? "({$rx->doctor->license_number})" : '')) : null,
                'facility' => $rx?->hospital?->name,
                'reason' => $m->reason,
                'recorded_by' => $m->creator?->name ?? 'System',
            ];
        })->values();

        return [
            'medicine' => $listing->medicine->displayName(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'opening_balance' => $opening,
            'total_received' => $entries->sum('received'),
            'total_issued' => $entries->sum('issued'),
            'closing_balance' => $balance,
            'stock_on_hand' => $listing->availableQuantity(),
            'entries' => $entries,
        ];
    }
}
