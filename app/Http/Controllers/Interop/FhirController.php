<?php

namespace App\Http\Controllers\Interop;

use App\Http\Controllers\Controller;
use App\Models\LabOrderItem;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PrescriptionItem;
use App\Models\Visit;
use App\Services\Interop\FhirMapper;
use App\Support\ReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HL7 FHIR R4 read API, scoped to the caller's organization.
 */
class FhirController extends Controller
{
    public function __construct(private FhirMapper $fhir) {}

    public function metadata(): JsonResponse
    {
        return $this->fhirJson($this->fhir->capability(url('/api/fhir')));
    }

    public function patients(Request $request): JsonResponse
    {
        $this->allow($request);
        $query = Patient::query()
            ->when($request->query('identifier'), fn ($q, $id) => $q->where('mrn', str_contains($id, '|') ? substr($id, strpos($id, '|') + 1) : $id))
            ->when($request->query('phone'), fn ($q, $p) => $q->where('phone', ReferenceGenerator::normalizePhone($p)))
            ->when($request->query('name'), fn ($q, $n) => $q->where('full_name', 'like', "%{$n}%"));

        return $this->bundle($query, fn ($p) => $this->fhir->patient($p), $request);
    }

    public function patient(Request $request, Patient $patient): JsonResponse
    {
        $this->allow($request);

        return $this->fhirJson($this->fhir->patient($patient));
    }

    public function encounters(Request $request): JsonResponse
    {
        $this->allow($request);

        return $this->bundle(Visit::with('doctor:id,name')->when($this->patientId($request), fn ($q, $id) => $q->where('patient_id', $id))->latest('checked_in_at'),
            fn ($v) => $this->fhir->encounter($v), $request);
    }

    public function observations(Request $request): JsonResponse
    {
        $this->allow($request);
        $query = LabOrderItem::with('test', 'order')
            ->whereIn('status', ['resulted', 'verified'])
            ->whereHas('order', fn ($o) => $o->where('tenant_id', $request->user()->tenant_id)
                ->when($this->patientId($request), fn ($q, $id) => $q->where('patient_id', $id))
                ->when($request->query('encounter'), fn ($q, $e) => $q->where('visit_id', (int) str_replace('Encounter/', '', $e))))
            ->latest('resulted_at');

        return $this->bundle($query, fn ($i) => $this->fhir->observation($i), $request);
    }

    public function medicationRequests(Request $request): JsonResponse
    {
        $this->allow($request);
        $query = PrescriptionItem::with('medicine', 'prescription.doctor:id,name')
            ->whereHas('prescription', fn ($p) => $p->where('tenant_id', $request->user()->tenant_id)
                ->when($this->patientId($request), fn ($q, $id) => $q->where('patient_id', $id))
                ->when($request->query('identifier'), fn ($q, $code) => $q->where('reference_code', strtoupper($code))));

        return $this->bundle($query, fn ($i) => $this->fhir->medicationRequest($i), $request);
    }

    public function medication(Request $request, Medicine $medicine): JsonResponse
    {
        $this->allow($request);

        return $this->fhirJson($this->fhir->medication($medicine));
    }

    /** Accepts either a full-access staff session or an integration token with fhir:read. */
    private function allow(Request $request): void
    {
        $token = $request->user()->currentAccessToken();
        $ok = ! $token || ! method_exists($token, 'can') || $token->can('fhir:read') || $token->can('*');
        abort_unless($ok && ($request->user()->can('integrations.manage') || $request->user()->can('patients.view') || $token?->can('fhir:read')), 403);
        abort_unless($request->user()->tenant_id, 403, 'FHIR access is per organization.');
    }

    private function patientId(Request $request): ?int
    {
        $p = $request->query('patient');

        return $p ? (int) str_replace('Patient/', '', $p) : null;
    }

    private function bundle($query, callable $map, Request $request): JsonResponse
    {
        $count = min(max((int) $request->query('_count', 50), 1), 200);
        $total = (clone $query)->count();

        return $this->fhirJson($this->fhir->bundle($query->limit($count)->get()->map($map), url('/api/fhir'), $total));
    }

    private function fhirJson(array $resource): JsonResponse
    {
        return response()->json($resource, 200, ['Content-Type' => 'application/fhir+json']);
    }
}
