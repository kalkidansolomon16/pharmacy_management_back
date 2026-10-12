<?php

namespace App\Services\Reports;

use App\Models\LabOrderItem;
use App\Models\Prescription;
use App\Models\Tenant;
use App\Models\Visit;
use App\Support\EthiopianCalendar;
use Carbon\CarbonImmutable;

/**
 * Monthly HMIS / DHIS2 report, generated from routine data instead of tally sheets:
 * OPD attendance by sex and age group, top morbidity (ICD-10), lab activity incl. malaria positivity,
 * prescriptions. Exported as DHIS2 dataValueSets JSON (data element codes map via settings.dhis2_mapping).
 */
class Dhis2ReportService
{
    /** Ethiopian HMIS age disaggregation. */
    public const AGE_GROUPS = ['<1' => [0, 0], '1-4' => [1, 4], '5-14' => [5, 14], '15-29' => [15, 29], '30-64' => [30, 64], '65+' => [65, 200]];

    public function monthly(Tenant $tenant, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        $visits = Visit::with('patient:id,gender,date_of_birth')->whereBetween('checked_in_at', [$from, $to])->where('status', '!=', 'cancelled')->get();

        $opd = [];
        foreach (self::AGE_GROUPS as $group => $_) {
            $opd[$group] = ['male' => 0, 'female' => 0];
        }
        $newPatients = 0;
        foreach ($visits as $visit) {
            $age = $visit->patient?->date_of_birth ? (int) $visit->patient->date_of_birth->diffInYears($visit->checked_in_at) : 30;
            $group = collect(self::AGE_GROUPS)->search(fn ($r) => $age >= $r[0] && $age <= $r[1]) ?: '30-64';
            $opd[$group][$visit->patient?->gender ?? 'female']++;
            $newPatients += Visit::where('patient_id', $visit->patient_id)->where('checked_in_at', '<', $visit->checked_in_at)->doesntExist() ? 1 : 0;
        }

        $morbidity = $visits->flatMap(fn ($v) => collect($v->diagnoses ?? [])->map(fn ($d) => $d + ['gender' => $v->patient?->gender]))
            ->groupBy('code')
            ->map(fn ($rows, $code) => [
                'code' => $code, 'name' => $rows->first()['name'] ?? $code, 'total' => $rows->count(),
                'male' => $rows->where('gender', 'male')->count(), 'female' => $rows->where('gender', 'female')->count(),
            ])
            ->sortByDesc('total')->take(10)->values();

        $labItems = LabOrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('tenant_id', $tenant->id)->whereBetween('created_at', [$from, $to]))
            ->whereIn('status', ['resulted', 'verified'])
            ->with('test:id,code,name,category')
            ->get();
        $malaria = $labItems->filter(fn ($i) => in_array($i->test?->code, ['BF', 'RDT-MAL'], true));

        $prescriptions = Prescription::whereBetween('issued_at', [$from, $to])->withCount('items')->get();

        $report = [
            'organisation' => $tenant->name,
            'org_unit' => $tenant->setting('dhis2_org_unit'),
            'period' => $from->format('Ym'),
            'period_label' => $from->format('F Y').' / '.EthiopianCalendar::format($from).' – '.EthiopianCalendar::format($to),
            'opd' => [
                'total' => $visits->count(),
                'new' => $newPatients,
                'repeat' => $visits->count() - $newPatients,
                'emergency' => $visits->where('type', 'emergency')->count(),
                'by_age_sex' => $opd,
            ],
            'top_morbidity' => $morbidity,
            'lab' => [
                'tests_performed' => $labItems->count(),
                'by_category' => $labItems->groupBy(fn ($i) => $i->test?->category ?? 'other')->map->count(),
                'malaria_tested' => $malaria->count(),
                'malaria_positive' => $malaria->where('flag', 'positive')->count(),
                'malaria_positivity_rate' => $malaria->count() ? round($malaria->where('flag', 'positive')->count() / $malaria->count() * 100, 1) : 0,
            ],
            'prescriptions' => [
                'issued' => $prescriptions->count(),
                'items' => $prescriptions->sum('items_count'),
                'average_items' => $prescriptions->count() ? round($prescriptions->avg('items_count'), 2) : 0,
            ],
        ];

        $report['dhis2'] = $this->dataValueSet($tenant, $report);

        return $report;
    }

    /** DHIS2 /api/dataValueSets payload. Data element ids come from the organization's mapping when set. */
    public function dataValueSet(Tenant $tenant, array $report): array
    {
        $map = $tenant->setting('dhis2_mapping') ?? [];
        $de = fn (string $code) => $map[$code] ?? $code;
        $values = [
            ['dataElement' => $de('OPD_VISITS_TOTAL'), 'value' => $report['opd']['total']],
            ['dataElement' => $de('OPD_VISITS_NEW'), 'value' => $report['opd']['new']],
            ['dataElement' => $de('OPD_VISITS_REPEAT'), 'value' => $report['opd']['repeat']],
            ['dataElement' => $de('EMERGENCY_VISITS'), 'value' => $report['opd']['emergency']],
            ['dataElement' => $de('MALARIA_TESTED'), 'value' => $report['lab']['malaria_tested']],
            ['dataElement' => $de('MALARIA_POSITIVE'), 'value' => $report['lab']['malaria_positive']],
            ['dataElement' => $de('LAB_TESTS_PERFORMED'), 'value' => $report['lab']['tests_performed']],
            ['dataElement' => $de('PRESCRIPTIONS_ISSUED'), 'value' => $report['prescriptions']['issued']],
        ];
        foreach ($report['opd']['by_age_sex'] as $group => $sexes) {
            foreach ($sexes as $sex => $count) {
                $values[] = ['dataElement' => $de('OPD_VISITS'), 'categoryOptionCombo' => $de("{$sex}_{$group}"), 'value' => $count];
            }
        }
        foreach ($report['top_morbidity'] as $row) {
            $values[] = ['dataElement' => $de('MORBIDITY_'.$row['code']), 'value' => $row['total']];
        }

        return [
            'dataSet' => $tenant->setting('dhis2_dataset') ?? 'OPD_MONTHLY',
            'period' => $report['period'],
            'orgUnit' => $report['org_unit'] ?? 'SET_ORG_UNIT_ID',
            'completeDate' => now()->toDateString(),
            'dataValues' => array_map(fn ($v) => $v + ['value' => (string) $v['value']], $values),
        ];
    }
}
