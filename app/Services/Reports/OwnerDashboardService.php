<?php

namespace App\Services\Reports;

use App\Models\Invoice;
use App\Models\MedicineBatch;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * One screen for owners: every branch and department - revenue, stock value, expiry losses,
 * money owed, patient flow and doctor productivity.
 */
class OwnerDashboardService
{
    public function for(User $user, int $days = 30): array
    {
        $root = $user->tenant;
        $ids = $root->familyIds();
        $from = CarbonImmutable::now()->subDays($days - 1)->startOfDay();
        $today = CarbonImmutable::today();

        $invoices = fn () => Invoice::withoutGlobalScopes()->whereIn('tenant_id', $ids)->where('document_type', 'invoice')->where('status', '!=', 'void');

        $branches = Tenant::whereIn('id', $ids)->orderByRaw('parent_id is not null')->orderBy('name')->get()->map(function (Tenant $t) use ($invoices, $from, $today) {
            $stock = MedicineBatch::withoutGlobalScopes()->where('tenant_id', $t->id)->sellable()->selectRaw('coalesce(sum(quantity * purchase_price), 0) as v')->value('v');
            $expiryLoss = DB::table('stock_movements as sm')->join('medicine_batches as b', 'b.id', '=', 'sm.batch_id')
                ->where('sm.tenant_id', $t->id)->where('sm.type', 'expired')->where('sm.created_at', '>=', $from)
                ->sum(DB::raw('abs(sm.quantity) * b.purchase_price'));
            $receivable = $invoices()->where('tenant_id', $t->id)
                ->selectRaw('coalesce(sum(patient_share - amount_paid), 0) + coalesce(sum(payer_share - payer_paid - payer_rejected), 0) as due')->value('due');

            return [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->type,
                'city' => $t->city,
                'is_branch' => (bool) $t->parent_id,
                'status' => $t->status,
                'revenue_today' => round((float) $invoices()->where('tenant_id', $t->id)->where('issued_at', '>=', $today)->sum('total'), 2),
                'revenue_period' => round((float) $invoices()->where('tenant_id', $t->id)->where('issued_at', '>=', $from)->sum('total'), 2),
                'stock_value' => round((float) $stock, 2),
                'expiry_loss' => round((float) $expiryLoss, 2),
                'receivables' => round((float) $receivable, 2),
                'visits_period' => Visit::withoutGlobalScopes()->where('tenant_id', $t->id)->where('checked_in_at', '>=', $from)->count(),
            ];
        });

        $departments = DB::table('invoice_items as ii')->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->whereIn('i.tenant_id', $ids)->where('i.document_type', 'invoice')->where('i.status', '!=', 'void')
            ->where('i.issued_at', '>=', $from)
            ->groupBy('ii.item_type')->selectRaw('ii.item_type as department, sum(ii.line_total) as revenue')
            ->orderByDesc('revenue')->get()
            ->map(fn ($r) => ['department' => $r->department, 'revenue' => round((float) $r->revenue, 2)]);

        $doctors = DB::table('visits as v')->join('users as u', 'u.id', '=', 'v.doctor_id')
            ->whereIn('v.tenant_id', $ids)->where('v.checked_in_at', '>=', $from)
            ->groupBy('u.id', 'u.name')
            ->selectRaw("u.id, u.name, count(*) as visits, sum(case when v.status = 'completed' then 1 else 0 end) as completed")
            ->orderByDesc('visits')->limit(10)->get()
            ->map(function ($d) use ($ids, $from) {
                $revenue = DB::table('invoices')->join('visits', 'visits.id', '=', 'invoices.visit_id')
                    ->whereIn('invoices.tenant_id', $ids)->where('visits.doctor_id', $d->id)->where('invoices.status', '!=', 'void')
                    ->where('invoices.issued_at', '>=', $from)->sum('invoices.total');
                $prescriptions = DB::table('prescriptions')->where('doctor_id', $d->id)->where('issued_at', '>=', $from)->count();

                return ['id' => $d->id, 'name' => $d->name, 'visits' => (int) $d->visits, 'completed' => (int) $d->completed,
                    'prescriptions' => $prescriptions, 'revenue' => round((float) $revenue, 2)];
            });

        $daily = $invoices()->where('issued_at', '>=', $from)
            ->selectRaw('date(issued_at) as day, sum(total) as value')->groupBy('day')->pluck('value', 'day');

        return [
            'period_days' => $days,
            'totals' => [
                'revenue_today' => round($branches->sum('revenue_today'), 2),
                'revenue_period' => round($branches->sum('revenue_period'), 2),
                'stock_value' => round($branches->sum('stock_value'), 2),
                'expiry_loss' => round($branches->sum('expiry_loss'), 2),
                'receivables' => round($branches->sum('receivables'), 2),
                'visits_period' => $branches->sum('visits_period'),
                'branches' => $branches->count(),
            ],
            'branches' => $branches->values(),
            'departments' => $departments,
            'doctors' => $doctors,
            'daily_revenue' => collect(CarbonPeriod::create($from, CarbonImmutable::today()))
                ->map(fn ($d) => ['date' => $d->toDateString(), 'value' => round((float) ($daily[$d->toDateString()] ?? 0), 2)])->all(),
        ];
    }
}
