<?php

namespace App\Services\Interop;

use App\Models\LabOrderItem;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PrescriptionItem;
use App\Models\Visit;

/**
 * Maps MedLink records to HL7 FHIR R4 resources, so EMRs, insurers and national systems
 * can read patient data in the standard format.
 */
class FhirMapper
{
    public const ICD10 = 'http://hl7.org/fhir/sid/icd-10';

    public function patient(Patient $p): array
    {
        $names = preg_split('/\s+/', trim($p->full_name));

        return array_filter([
            'resourceType' => 'Patient',
            'id' => (string) $p->id,
            'meta' => ['lastUpdated' => $p->updated_at?->toIso8601String()],
            'identifier' => array_values(array_filter([
                $p->mrn ? ['use' => 'usual', 'type' => ['text' => 'Medical record number'], 'system' => "urn:medlink:tenant:{$p->tenant_id}:mrn", 'value' => $p->mrn] : null,
                ['system' => 'urn:medlink:patient', 'value' => (string) $p->id],
            ])),
            'name' => [['text' => $p->full_name, 'given' => array_slice($names, 0, -1) ?: $names, 'family' => count($names) > 1 ? end($names) : null]],
            'telecom' => [['system' => 'phone', 'value' => $p->phone, 'use' => 'mobile']],
            'gender' => $p->gender,
            'birthDate' => $p->date_of_birth?->toDateString(),
            'address' => [array_filter(['text' => $p->address, 'city' => $p->city, 'country' => 'ET'])],
            'managingOrganization' => ['reference' => "Organization/{$p->tenant_id}"],
        ], fn ($v) => $v !== null);
    }

    public function encounter(Visit $v): array
    {
        $status = match ($v->status) {
            'waiting' => 'arrived',
            'in_consultation', 'awaiting_lab', 'awaiting_review' => 'in-progress',
            'completed' => 'finished',
            'cancelled' => 'cancelled',
            default => 'unknown',
        };

        return array_filter([
            'resourceType' => 'Encounter',
            'id' => (string) $v->id,
            'identifier' => [['system' => 'urn:medlink:visit', 'value' => $v->visit_number]],
            'status' => $status,
            'class' => ['system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode', 'code' => $v->type === 'emergency' ? 'EMER' : 'AMB'],
            'priority' => ['text' => $v->priority],
            'subject' => ['reference' => "Patient/{$v->patient_id}"],
            'participant' => $v->doctor_id ? [['individual' => ['reference' => "Practitioner/{$v->doctor_id}", 'display' => $v->doctor?->name]]] : null,
            'period' => array_filter(['start' => $v->checked_in_at?->toIso8601String(), 'end' => $v->completed_at?->toIso8601String()]),
            'reasonCode' => $v->chief_complaint ? [['text' => $v->chief_complaint]] : null,
            'diagnosis' => collect($v->diagnoses ?? [])->values()->map(fn ($d, $i) => [
                'condition' => ['display' => $d['name'] ?? $d['code']],
                'use' => ['text' => 'discharge diagnosis'],
                'rank' => $i + 1,
                'extension' => [['url' => 'urn:medlink:icd10', 'valueCoding' => ['system' => self::ICD10, 'code' => $d['code'], 'display' => $d['name'] ?? null]]],
            ])->all() ?: null,
            'serviceProvider' => ['reference' => "Organization/{$v->tenant_id}"],
        ], fn ($x) => $x !== null);
    }

    public function observation(LabOrderItem $i): array
    {
        $interpretation = ['high' => 'H', 'low' => 'L', 'normal' => 'N', 'critical' => 'AA', 'positive' => 'POS', 'negative' => 'NEG'][$i->flag] ?? null;
        $test = $i->test;

        return array_filter([
            'resourceType' => 'Observation',
            'id' => (string) $i->id,
            'status' => match ($i->status) {
                'verified' => 'final', 'resulted' => 'preliminary', default => 'registered'
            },
            'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/observation-category', 'code' => 'laboratory']]]],
            'code' => ['coding' => [['system' => 'urn:medlink:lab-test', 'code' => $test?->code, 'display' => $test?->name]], 'text' => $test?->name],
            'subject' => ['reference' => "Patient/{$i->order->patient_id}"],
            'encounter' => $i->order->visit_id ? ['reference' => "Encounter/{$i->order->visit_id}"] : null,
            'effectiveDateTime' => ($i->order->collected_at ?? $i->order->created_at)?->toIso8601String(),
            'issued' => $i->resulted_at?->toIso8601String(),
            'valueQuantity' => $i->result_value !== null ? ['value' => $i->result_value, 'unit' => $i->unit] : null,
            'valueString' => $i->result_value === null ? $i->result_text : null,
            'interpretation' => $interpretation ? [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v3-ObservationInterpretation', 'code' => $interpretation]]]] : null,
            'referenceRange' => $test && ($test->reference_low !== null || $test->reference_text) ? [array_filter([
                'low' => $test->reference_low !== null ? ['value' => $test->reference_low, 'unit' => $test->unit] : null,
                'high' => $test->reference_high !== null ? ['value' => $test->reference_high, 'unit' => $test->unit] : null,
                'text' => $test->reference_text,
            ])] : null,
            'note' => $i->remarks ? [['text' => $i->remarks]] : null,
        ], fn ($x) => $x !== null);
    }

    public function medicationRequest(PrescriptionItem $item): array
    {
        $rx = $item->prescription;
        $status = match ($rx->status) {
            'active', 'partially_dispensed' => 'active',
            'dispensed' => 'completed',
            'cancelled' => 'cancelled',
            'expired' => 'stopped',
            default => 'unknown',
        };

        return array_filter([
            'resourceType' => 'MedicationRequest',
            'id' => (string) $item->id,
            'groupIdentifier' => ['system' => 'urn:medlink:prescription', 'value' => $rx->reference_code],
            'status' => $status,
            'intent' => 'order',
            'medicationReference' => ['reference' => "Medication/{$item->medicine_id}", 'display' => $item->medicine?->displayName()],
            'subject' => ['reference' => "Patient/{$rx->patient_id}"],
            'encounter' => $rx->visit_id ? ['reference' => "Encounter/{$rx->visit_id}"] : null,
            'authoredOn' => $rx->issued_at?->toIso8601String(),
            'requester' => ['reference' => "Practitioner/{$rx->doctor_id}", 'display' => $rx->doctor?->name],
            'dosageInstruction' => [['text' => trim("{$item->dosage}, {$item->frequency} for {$item->duration_days} days. {$item->instructions}")]],
            'dispenseRequest' => [
                'quantity' => ['value' => $item->total_quantity, 'unit' => $item->medicine?->unit],
                'validityPeriod' => ['start' => $rx->issued_at?->toDateString(), 'end' => $rx->expires_at?->toDateString()],
            ],
            'extension' => [['url' => 'urn:medlink:dispensed-quantity', 'valueInteger' => $item->dispensed_quantity]],
        ], fn ($x) => $x !== null);
    }

    public function medication(Medicine $m): array
    {
        return array_filter([
            'resourceType' => 'Medication',
            'id' => (string) $m->id,
            'code' => ['text' => $m->displayName(), 'coding' => $m->barcode ? [['system' => 'https://www.gs1.org/gtin', 'code' => $m->barcode]] : null],
            'status' => $m->is_active ? 'active' : 'inactive',
            'manufacturer' => $m->manufacturer ? ['display' => $m->manufacturer] : null,
            'form' => ['text' => $m->dosage_form],
            'ingredient' => [['itemCodeableConcept' => ['text' => $m->generic_name], 'strength' => ['numerator' => ['value' => 1, 'unit' => $m->strength]]]],
        ]);
    }

    /** @param iterable<array> $resources */
    public function bundle(iterable $resources, string $baseUrl, int $total): array
    {
        $entries = collect($resources)->map(fn ($r) => ['fullUrl' => "{$baseUrl}/{$r['resourceType']}/{$r['id']}", 'resource' => $r, 'search' => ['mode' => 'match']])->values();

        return ['resourceType' => 'Bundle', 'type' => 'searchset', 'total' => $total, 'entry' => $entries->all()];
    }

    public function capability(string $baseUrl): array
    {
        $resource = fn (string $type, array $params) => [
            'type' => $type,
            'interaction' => [['code' => 'read'], ['code' => 'search-type']],
            'searchParam' => array_map(fn ($p) => ['name' => $p, 'type' => 'token'], $params),
        ];

        return [
            'resourceType' => 'CapabilityStatement',
            'status' => 'active',
            'date' => now()->toDateString(),
            'kind' => 'instance',
            'software' => ['name' => 'MedLink Ethiopia', 'version' => '2.0'],
            'implementation' => ['description' => 'MedLink Ethiopia FHIR R4 API (read-only)', 'url' => $baseUrl],
            'fhirVersion' => '4.0.1',
            'format' => ['application/fhir+json'],
            'rest' => [[
                'mode' => 'server',
                'security' => ['description' => 'Bearer token issued under Settings → Integrations (ability fhir:read).'],
                'resource' => [
                    $resource('Patient', ['identifier', 'phone', 'name']),
                    $resource('Encounter', ['patient']),
                    $resource('Observation', ['patient', 'encounter']),
                    $resource('MedicationRequest', ['patient', 'identifier']),
                    $resource('Medication', []),
                ],
            ]],
        ];
    }
}
