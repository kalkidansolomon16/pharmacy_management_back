<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Patient;
use App\Models\Tenant;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

/**
 * Platform team: every patient registered at any hospital / clinic, and which one.
 * Oversight only - identity and activity counts, no clinical details. Viewing is recorded in the audit log.
 */
class PatientController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'tenant_id' => ['nullable', 'integer'],
            'gender' => ['nullable', 'in:male,female'],
            'linked' => ['nullable', 'boolean'], // has a MedLink patient account
        ]);

        $patients = Patient::acrossTenants()
            ->with('tenant:id,name,city,sub_city,type,status')
            ->withCount([
                'visits' => fn ($q) => $q->withoutGlobalScopes(),
                'prescriptions' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->withMax(['visits as last_visit_at' => fn ($q) => $q->withoutGlobalScopes()], 'checked_in_at')
            ->when($data['search'] ?? null, function ($q, $t) {
                // Phones are stored as +2519..., so "0911 22 33 44" or "251911..." should match too
                $digits = preg_replace('/\D/', '', $t);
                $local = preg_replace('/^(251|0)/', '', $digits);
                $q->where(fn ($w) => $w->where('full_name', 'like', "%{$t}%")->orWhere('mrn', 'like', "%{$t}%")
                    ->orWhere('phone', 'like', "%{$t}%")
                    ->when(strlen($local) >= 4, fn ($p) => $p->orWhere('phone', 'like', "%{$local}%")));
            })
            ->when($data['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($data['gender'] ?? null, fn ($q, $g) => $q->where('gender', $g))
            ->when($request->has('linked'), fn ($q) => $request->boolean('linked') ? $q->whereNotNull('user_id') : $q->whereNull('user_id'))
            ->latest()
            ->paginate($this->perPage(25));

        // Record that the platform team looked at patient records (at most once per half hour per person)
        $recent = ActivityLog::query()->where('user_id', $request->user()->id)->where('action', 'patients_viewed')
            ->where('created_at', '>=', now()->subMinutes(30))->exists();
        if (! $recent) {
            $this->logger->log('patients_viewed', null, [], "{$request->user()->name} opened the all-patients list");
        }

        return response()->json([
            'data' => $patients->getCollection()->map(fn (Patient $p) => [
                'id' => $p->id,
                'mrn' => $p->mrn,
                'full_name' => $p->full_name,
                'phone' => $p->phone,
                'gender' => $p->gender,
                'age' => $p->age(),
                'city' => $p->city,
                'has_account' => $p->user_id !== null,
                'visits_count' => $p->visits_count,
                'prescriptions_count' => $p->prescriptions_count,
                'last_visit_at' => $p->last_visit_at,
                'registered_at' => $p->created_at,
                'hospital' => $p->tenant ? [
                    'id' => $p->tenant->id, 'name' => $p->tenant->name, 'city' => $p->tenant->city,
                    'sub_city' => $p->tenant->sub_city, 'status' => $p->tenant->status,
                ] : null,
            ]),
            'meta' => [
                'current_page' => $patients->currentPage(),
                'last_page' => $patients->lastPage(),
                'per_page' => $patients->perPage(),
                'total' => $patients->total(),
                'from' => $patients->firstItem(),
                'to' => $patients->lastItem(),
                // For the hospital filter: every hospital with how many patients it has
                'hospitals' => Tenant::where('type', Tenant::TYPE_HOSPITAL)
                    ->withCount(['patients' => fn ($q) => $q->withoutGlobalScopes()])
                    ->orderBy('name')->get(['id', 'name', 'city'])
                    ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'city' => $t->city, 'patients' => $t->patients_count]),
                'totals' => [
                    'patients' => Patient::acrossTenants()->count(),
                    'with_account' => Patient::acrossTenants()->whereNotNull('user_id')->count(),
                    'new_30d' => Patient::acrossTenants()->where('created_at', '>=', now()->subDays(30))->count(),
                ],
            ],
        ]);
    }
}
