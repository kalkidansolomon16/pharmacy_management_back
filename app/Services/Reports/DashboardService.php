<?php

namespace App\Services\Reports;

use App\Http\Resources\MedicineBatchResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PharmacyMedicineResource;
use App\Http\Resources\PrescriptionResource;
use App\Http\Resources\TenantResource;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Carbon\CarbonImmutable;

/**
 * Role-aware dashboard payloads.
 */
class DashboardService
{
    public function __construct(private ReportService $reports, private InventoryService $inventory) {}

    public function for(User $user): array
    {
        return match (true) {
            $user->isSuperAdmin() => ['type' => 'platform'] + $this->platform(),
            $user->tenant?->isPharmacy() => ['type' => 'pharmacy'] + $this->pharmacy(),
            $user->tenant?->isHospital() => ['type' => 'hospital'] + $this->hospital($user),
            default => ['type' => 'customer'] + $this->customer($user),
        };
    }

    private function platform(): array
    {
        [$from, $to] = ReportService::range(null, null, 14);
        $fulfilled = Order::whereIn('status', Order::FULFILLED_STATUSES);

        return [
            'stats' => [
                'pharmacies' => Tenant::pharmacies()->active()->count(),
                'hospitals' => Tenant::where('type', 'hospital')->active()->count(),
                'pending_approvals' => Tenant::where('status', 'pending')->count(),
                'users' => User::count(),
                'orders_30d' => (clone $fulfilled)->where('fulfilled_at', '>=', now()->subDays(30))->count(),
                'gmv_30d' => round((float) (clone $fulfilled)->where('fulfilled_at', '>=', now()->subDays(30))->sum('total_amount'), 2),
                'prescriptions_30d' => Prescription::where('issued_at', '>=', now()->subDays(30))->count(),
            ],
            'daily_gmv' => $this->reports->dailySeries(clone $fulfilled, 'fulfilled_at', $from, $to, 'sum(total_amount)'),
            'pending_tenants' => TenantResource::collection(Tenant::where('status', 'pending')->latest()->limit(6)->get())->resolve(),
            'pharmacies_by_city' => Tenant::pharmacies()->active()->selectRaw('city, count(*) as total')->groupBy('city')->orderByDesc('total')->limit(8)->get(),
        ];
    }

    private function pharmacy(): array
    {
        [$from, $to] = ReportService::range(null, null, 14);
        $today = CarbonImmutable::today();
        $fulfilled = Order::whereIn('status', Order::FULFILLED_STATUSES);
        $alerts = $this->inventory->alerts(6);
        $sales30 = $this->reports->sales(CarbonImmutable::now()->subDays(29)->startOfDay(), CarbonImmutable::now()->endOfDay());

        return [
            'stats' => [
                'sales_today' => round((float) (clone $fulfilled)->where('fulfilled_at', '>=', $today)->sum('total_amount'), 2),
                'orders_today' => (clone $fulfilled)->where('fulfilled_at', '>=', $today)->count(),
                'sales_yesterday' => round((float) (clone $fulfilled)->whereBetween('fulfilled_at', [$today->subDay(), $today])->sum('total_amount'), 2),
                'open_orders' => Order::whereIn('status', Order::OPEN_STATUSES)->count(),
                'pending_orders' => Order::where('status', 'pending')->count(),
                'low_stock' => $alerts['low_stock_count'],
                'out_of_stock' => $alerts['out_of_stock_count'],
                'expiring' => $alerts['expiring_count'],
                'revenue_30d' => $sales30['totals']['revenue'],
                'margin_30d' => $sales30['totals']['margin_percent'],
            ],
            'daily_sales' => $this->reports->dailySeries(clone $fulfilled, 'fulfilled_at', $from, $to, 'sum(total_amount)'),
            'by_payment_method' => $sales30['by_payment_method'],
            'by_channel' => $sales30['by_channel'],
            'top_medicines' => $sales30['top_medicines']->take(5)->values(),
            'recent_orders' => OrderResource::collection(Order::withCount('items')->latest()->limit(6)->get())->resolve(),
            'low_stock' => PharmacyMedicineResource::collection($alerts['low_stock'])->resolve(),
            'expiring' => MedicineBatchResource::collection($alerts['expiring'])->resolve(),
        ];
    }

    private function hospital(User $user): array
    {
        [$from, $to] = ReportService::range(null, null, 14);
        $doctorId = $user->hasRole(User::ROLE_DOCTOR) ? $user->id : null;
        $prescriptions = Prescription::query()->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId));
        $report = $this->reports->prescriptions(CarbonImmutable::now()->subDays(29)->startOfDay(), CarbonImmutable::now()->endOfDay(), $doctorId);

        return [
            'stats' => [
                'patients' => Patient::count(),
                'prescriptions_today' => (clone $prescriptions)->where('issued_at', '>=', today())->count(),
                'prescriptions_30d' => $report['totals']['prescriptions'],
                'active_prescriptions' => (clone $prescriptions)->dispensable()->count(),
                'dispense_rate' => $report['totals']['dispense_rate'],
                'doctors' => User::where('tenant_id', $user->tenant_id)->role(User::ROLE_DOCTOR)->count(),
            ],
            'daily_prescriptions' => $this->reports->dailySeries(clone $prescriptions, 'issued_at', $from, $to, 'count(*)'),
            'by_status' => $report['by_status'],
            'top_medicines' => $report['top_medicines']->take(6)->values(),
            'recent_prescriptions' => PrescriptionResource::collection(
                (clone $prescriptions)->with('patient', 'doctor:id,name')->withCount('items')->latest('issued_at')->limit(6)->get()
            )->resolve(),
        ];
    }

    private function customer(User $user): array
    {
        $orders = Order::where('user_id', $user->id);

        return [
            'stats' => [
                'open_orders' => (clone $orders)->whereIn('status', Order::OPEN_STATUSES)->count(),
                'completed_orders' => (clone $orders)->whereIn('status', Order::FULFILLED_STATUSES)->count(),
                'total_spent' => round((float) (clone $orders)->whereIn('status', Order::FULFILLED_STATUSES)->sum('total_amount'), 2),
            ],
            'recent_orders' => OrderResource::collection((clone $orders)->with('pharmacy')->withCount('items')->latest()->limit(5)->get())->resolve(),
        ];
    }
}
