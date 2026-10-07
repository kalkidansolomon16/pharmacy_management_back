<?php

namespace App\Services\Reports;

use App\Models\MedicineBatch;
use App\Models\Order;
use App\Models\PharmacyMedicine;
use App\Models\Prescription;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregations behind dashboards and reports. Queries run through Eloquent models,
 * so the tenant global scope keeps every number inside the caller's organization.
 */
class ReportService
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(?string $from, ?string $to, int $defaultDays = 30): array
    {
        $end = $to ? CarbonImmutable::parse($to)->endOfDay() : CarbonImmutable::now()->endOfDay();
        $start = $from ? CarbonImmutable::parse($from)->startOfDay() : $end->subDays($defaultDays - 1)->startOfDay();

        return [$start, $end];
    }

    public function sales(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = Order::query()->whereIn('status', Order::FULFILLED_STATUSES)->whereBetween('fulfilled_at', [$from, $to]);

        $totals = (clone $orders)->selectRaw('count(*) as orders, coalesce(sum(total_amount),0) as revenue, coalesce(sum(discount),0) as discount')->first();

        $cost = (float) DB::table('order_item_batches as oib')
            ->join('order_items as oi', 'oi.id', '=', 'oib.order_item_id')
            ->join('medicine_batches as b', 'b.id', '=', 'oib.batch_id')
            ->whereIn('oi.order_id', (clone $orders)->select('id'))
            ->sum(DB::raw('oib.quantity * b.purchase_price'));

        $itemsSold = (int) DB::table('order_items')->whereIn('order_id', (clone $orders)->select('id'))->sum('fulfilled_quantity');

        $revenue = (float) $totals->revenue;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'orders' => (int) $totals->orders,
                'revenue' => round($revenue, 2),
                'discount' => round((float) $totals->discount, 2),
                'items_sold' => $itemsSold,
                'average_basket' => $totals->orders ? round($revenue / $totals->orders, 2) : 0,
                'cost_of_goods' => round($cost, 2),
                'gross_profit' => round($revenue - $cost, 2),
                'margin_percent' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : 0,
            ],
            'by_channel' => (clone $orders)->selectRaw('channel, count(*) as orders, sum(total_amount) as revenue')->groupBy('channel')->get()
                ->map(fn ($r) => ['channel' => $r->channel, 'orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 2)]),
            'by_payment_method' => (clone $orders)->selectRaw('payment_method, count(*) as orders, sum(total_amount) as revenue')->groupBy('payment_method')->orderByDesc('revenue')->get()
                ->map(fn ($r) => ['payment_method' => $r->payment_method, 'orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 2)]),
            'daily' => $this->dailySeries(clone $orders, 'fulfilled_at', $from, $to, 'sum(total_amount)'),
            'top_medicines' => $this->topMedicines(clone $orders),
        ];
    }

    public function topMedicines($orders, int $limit = 10): Collection
    {
        return DB::table('order_items as oi')
            ->join('medicines as m', 'm.id', '=', 'oi.medicine_id')
            ->whereIn('oi.order_id', $orders->select('id'))
            ->groupBy('m.id', 'm.generic_name', 'm.strength', 'm.brand_name')
            ->selectRaw('m.id, m.generic_name, m.strength, m.brand_name, sum(oi.fulfilled_quantity) as quantity, sum(oi.line_total) as revenue')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'medicine_id' => $r->id,
                'name' => trim("{$r->generic_name} {$r->strength}"),
                'brand_name' => $r->brand_name,
                'quantity' => (int) $r->quantity,
                'revenue' => round((float) $r->revenue, 2),
            ]);
    }

    public function inventory(): array
    {
        $rows = PharmacyMedicine::query()
            ->with('medicine')
            ->withAvailableQuantity()
            ->withNearestExpiry()
            ->withSum(['batches as stock_cost' => fn ($q) => $q->sellable()], DB::raw('quantity * purchase_price'))
            ->get()
            ->map(function (PharmacyMedicine $l) {
                $qty = (int) $l->available_quantity;

                return [
                    'id' => $l->id,
                    'name' => $l->medicine->displayName(),
                    'dosage_form' => $l->medicine->dosage_form,
                    'quantity' => $qty,
                    'reorder_level' => $l->reorder_level,
                    'price' => (float) $l->price,
                    'stock_cost' => round((float) $l->stock_cost, 2),
                    'retail_value' => round($qty * (float) $l->price, 2),
                    'nearest_expiry' => $l->nearest_expiry ? substr((string) $l->nearest_expiry, 0, 10) : null,
                    'status' => match (true) {
                        $qty === 0 => 'out_of_stock',
                        $qty <= $l->reorder_level => 'low_stock',
                        default => 'in_stock',
                    },
                ];
            })
            ->sortBy('name')
            ->values();

        return [
            'totals' => [
                'products' => $rows->count(),
                'units' => $rows->sum('quantity'),
                'stock_cost' => round($rows->sum('stock_cost'), 2),
                'retail_value' => round($rows->sum('retail_value'), 2),
                'low_stock' => $rows->where('status', 'low_stock')->count(),
                'out_of_stock' => $rows->where('status', 'out_of_stock')->count(),
                'expiring_90_days' => MedicineBatch::expiringWithin(90)->count(),
                'expired_write_off_value' => round((float) DB::table('stock_movements as sm')
                    ->join('medicine_batches as b', 'b.id', '=', 'sm.batch_id')
                    ->where('sm.type', 'expired')
                    ->when(app(TenantContext::class)->id(), fn ($q, $t) => $q->where('sm.tenant_id', $t))
                    ->sum(DB::raw('abs(sm.quantity) * b.purchase_price')), 2),
            ],
            'rows' => $rows,
        ];
    }

    public function prescriptions(CarbonImmutable $from, CarbonImmutable $to, ?int $doctorId = null): array
    {
        $base = Prescription::query()
            ->whereBetween('issued_at', [$from, $to])
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId));

        $byStatus = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $total = (int) $byStatus->sum();
        $dispensed = (int) (($byStatus['dispensed'] ?? 0) + ($byStatus['partially_dispensed'] ?? 0));

        $items = DB::table('prescription_items as pi')
            ->join('medicines as m', 'm.id', '=', 'pi.medicine_id')
            ->whereIn('pi.prescription_id', (clone $base)->select('id'));

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'prescriptions' => $total,
                'patients' => (clone $base)->distinct()->count('patient_id'),
                'dispense_rate' => $total ? round($dispensed / $total * 100, 1) : 0,
                'units_prescribed' => (int) (clone $items)->sum('pi.total_quantity'),
                'units_dispensed' => (int) (clone $items)->sum('pi.dispensed_quantity'),
            ],
            'by_status' => $byStatus,
            'by_doctor' => (clone $base)->join('users', 'users.id', '=', 'prescriptions.doctor_id')
                ->selectRaw('users.id, users.name, count(*) as total')
                ->groupBy('users.id', 'users.name')->orderByDesc('total')->limit(10)->get(),
            'top_medicines' => (clone $items)
                ->groupBy('m.id', 'm.generic_name', 'm.strength')
                ->selectRaw('m.id, m.generic_name, m.strength, count(*) as times, sum(pi.total_quantity) as quantity')
                ->orderByDesc('times')->limit(10)->get()
                ->map(fn ($r) => ['medicine_id' => $r->id, 'name' => trim("{$r->generic_name} {$r->strength}"), 'times' => (int) $r->times, 'quantity' => (int) $r->quantity]),
            'daily' => $this->dailySeries(clone $base, 'issued_at', $from, $to, 'count(*)'),
        ];
    }

    /**
     * One point per day, zero-filled, as [{date, value}].
     */
    public function dailySeries($query, string $column, CarbonImmutable $from, CarbonImmutable $to, string $aggregate): array
    {
        $table = $query->getModel()->getTable();
        $values = $query
            ->selectRaw("date({$table}.{$column}) as day, {$aggregate} as value")
            ->groupBy('day')
            ->pluck('value', 'day');

        return collect(CarbonPeriod::create($from->startOfDay(), $to->startOfDay()))
            ->map(fn ($d) => ['date' => $d->toDateString(), 'value' => round((float) ($values[$d->toDateString()] ?? 0), 2)])
            ->all();
    }
}
