<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Prescription;
use App\Models\User;
use App\Services\Reports\DashboardService;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports, private ReportExporter $exporter, private TenantContext $tenants) {}

    public function dashboard(Request $request, DashboardService $dashboards)
    {
        return $this->respond($dashboards->for($request->user()));
    }

    public function sales(Request $request)
    {
        Gate::authorize('reports.sales');
        [$from, $to] = $this->range($request);

        return $this->respond($this->scoped($request, fn () => $this->reports->sales($from, $to)));
    }

    public function inventory(Request $request)
    {
        Gate::authorize('reports.inventory');

        return $this->respond($this->reports->inventory());
    }

    public function prescriptions(Request $request)
    {
        Gate::authorize('reports.prescriptions');
        [$from, $to] = $this->range($request);

        return $this->respond($this->reports->prescriptions($from, $to, $this->doctorFilter($request)));
    }

    public function export(Request $request, string $report)
    {
        $format = $request->validate(['format' => ['required', 'in:csv,pdf']])['format'];
        [$from, $to] = $this->range($request);
        $period = $from->format('d M Y').' - '.$to->format('d M Y');

        return match ($report) {
            'sales' => $this->exportSales($request, $format, $from, $to, $period),
            'inventory' => $this->exportInventory($format),
            'prescriptions' => $this->exportPrescriptions($request, $format, $from, $to, $period),
            default => abort(404),
        };
    }

    private function exportSales(Request $request, string $format, $from, $to, string $period)
    {
        Gate::authorize('reports.sales');

        return $this->scoped($request, function () use ($format, $from, $to, $period) {
            $summary = $this->reports->sales($from, $to)['totals'];
            $orders = Order::with('pharmacy:id,name')->withSum('items as units', 'fulfilled_quantity')
                ->whereIn('status', Order::FULFILLED_STATUSES)
                ->whereBetween('fulfilled_at', [$from, $to])
                ->orderBy('fulfilled_at')
                ->get()
                ->map(fn ($o) => [
                    'date' => $o->fulfilled_at->format('Y-m-d H:i'),
                    'order' => $o->order_number,
                    'pharmacy' => $o->pharmacy?->name,
                    'channel' => $o->channel === 'walk_in' ? 'Walk-in' : 'Online',
                    'customer' => $o->customer_name,
                    'payment' => str_replace('_', ' ', $o->payment_method),
                    'units' => (int) $o->units,
                    'total' => number_format((float) $o->total_amount, 2),
                ]);

            return $this->exporter->export($format, 'Sales Report', $period, [
                'date' => 'Date', 'order' => 'Order #', 'pharmacy' => 'Pharmacy', 'channel' => 'Channel',
                'customer' => 'Customer', 'payment' => 'Payment', 'units' => 'Units', 'total' => 'Total (ETB)',
            ], $orders, [
                'Orders' => $summary['orders'],
                'Revenue (ETB)' => number_format($summary['revenue'], 2),
                'Gross profit (ETB)' => number_format($summary['gross_profit'], 2),
                'Margin' => $summary['margin_percent'].'%',
                'Items sold' => $summary['items_sold'],
                'Avg. basket (ETB)' => number_format($summary['average_basket'], 2),
                'Discounts (ETB)' => number_format($summary['discount'], 2),
            ]);
        });
    }

    private function exportInventory(string $format)
    {
        Gate::authorize('reports.inventory');
        $report = $this->reports->inventory();
        $t = $report['totals'];

        $rows = $report['rows']->map(fn ($r) => [
            ...$r,
            'price' => number_format($r['price'], 2),
            'stock_cost' => number_format($r['stock_cost'], 2),
            'retail_value' => number_format($r['retail_value'], 2),
            'status' => ucwords(str_replace('_', ' ', $r['status'])),
        ]);

        return $this->exporter->export($format, 'Inventory Valuation', 'As of '.now()->format('d M Y'), [
            'name' => 'Medicine', 'dosage_form' => 'Form', 'quantity' => 'In stock', 'reorder_level' => 'Reorder at',
            'price' => 'Price (ETB)', 'stock_cost' => 'Cost value', 'retail_value' => 'Retail value',
            'nearest_expiry' => 'Next expiry', 'status' => 'Status',
        ], $rows, [
            'Products' => $t['products'],
            'Units' => $t['units'],
            'Cost value (ETB)' => number_format($t['stock_cost'], 2),
            'Retail value (ETB)' => number_format($t['retail_value'], 2),
            'Low stock' => $t['low_stock'],
            'Out of stock' => $t['out_of_stock'],
            'Expiring in 90 days' => $t['expiring_90_days'],
            'Expired write-off (ETB)' => number_format($t['expired_write_off_value'], 2),
        ]);
    }

    private function exportPrescriptions(Request $request, string $format, $from, $to, string $period)
    {
        Gate::authorize('reports.prescriptions');
        $doctorId = $this->doctorFilter($request);
        $summary = $this->reports->prescriptions($from, $to, $doctorId)['totals'];

        $rows = Prescription::with('patient', 'doctor:id,name')->withCount('items')
            ->whereBetween('issued_at', [$from, $to])
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->orderBy('issued_at')
            ->get()
            ->map(fn ($p) => [
                'date' => $p->issued_at->format('Y-m-d'),
                'code' => $p->reference_code,
                'patient' => $p->patient?->full_name,
                'mrn' => $p->patient?->mrn,
                'doctor' => $p->doctor?->name,
                'items' => $p->items_count,
                'status' => ucwords(str_replace('_', ' ', $p->status)),
                'expires' => $p->expires_at->format('Y-m-d'),
            ]);

        return $this->exporter->export($format, 'Prescription Report', $period, [
            'date' => 'Issued', 'code' => 'Reference', 'patient' => 'Patient', 'mrn' => 'MRN',
            'doctor' => 'Doctor', 'items' => 'Items', 'status' => 'Status', 'expires' => 'Expires',
        ], $rows, [
            'Prescriptions' => $summary['prescriptions'],
            'Patients' => $summary['patients'],
            'Dispense rate' => $summary['dispense_rate'].'%',
            'Units prescribed' => $summary['units_prescribed'],
        ]);
    }

    private function range(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return ReportService::range($request->query('from'), $request->query('to'));
    }

    /** Super admins may drill into a single pharmacy with ?tenant_id=. */
    private function scoped(Request $request, callable $callback): mixed
    {
        if ($request->user()->isSuperAdmin() && $request->query('tenant_id')) {
            return $this->tenants->run((int) $request->query('tenant_id'), $callback);
        }

        return $callback();
    }

    /** Doctors only ever see their own numbers. */
    private function doctorFilter(Request $request): ?int
    {
        $user = $request->user();

        return $user->hasRole(User::ROLE_DOCTOR) ? $user->id : ($request->query('doctor_id') ? (int) $request->query('doctor_id') : null);
    }
}
