<?php

namespace App\Services\Clinical;

use App\Exceptions\BusinessRuleException;
use App\Models\Patient;
use App\Models\PayerMembership;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\ActivityLogger;
use App\Services\Billing\BillingService;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The outpatient journey: check-in -> triage -> consultation -> (lab) -> review -> completed.
 */
class ClinicalService
{
    public function __construct(private BillingService $billing, private ActivityLogger $logger) {}

    public function checkIn(Patient $patient, array $data): Visit
    {
        if (Visit::where('patient_id', $patient->id)->whereIn('status', Visit::OPEN_STATUSES)->exists()) {
            throw BusinessRuleException::withErrors('patient_id', "{$patient->full_name} already has an open visit today.");
        }

        $membership = null;
        if (! empty($data['membership_id'])) {
            $membership = PayerMembership::with('payer')->where('patient_id', $patient->id)->findOrFail($data['membership_id']);
            if (! $membership->isValid()) {
                throw BusinessRuleException::withErrors('membership_id', 'This coverage is expired or suspended - register the visit as cash, or renew the membership.');
            }
        }

        $visit = DB::transaction(function () use ($patient, $data, $membership) {
            $tenant = Auth::user()->tenant;
            $firstVisit = ! Visit::where('patient_id', $patient->id)->exists();

            $visit = Visit::create([
                'tenant_id' => $tenant->id,
                'patient_id' => $patient->id,
                'visit_number' => DocumentNumber::next($tenant->id, 'visit'),
                'queue_number' => Visit::whereDate('checked_in_at', today())->count() + 1,
                'type' => $data['type'] ?? 'opd',
                'department' => $data['department'] ?? null,
                'priority' => ($data['type'] ?? 'opd') === 'emergency' ? 'urgent' : ($data['priority'] ?? 'normal'),
                'doctor_id' => $data['doctor_id'] ?? null,
                'membership_id' => $membership?->id,
                'chief_complaint' => $data['chief_complaint'] ?? null,
                'status' => 'waiting',
                'checked_in_by' => Auth::id(),
                'checked_in_at' => now(),
            ]);

            // Card (registration) fee for new patients and the consultation fee, per the price list
            if ($tenant->hasModule('billing') && $tenant->setting('registration_fee_on_check_in')) {
                $codes = array_filter([$firstVisit ? 'REG' : null, match ($visit->type) {
                    'emergency' => 'CONS-EMG', 'follow_up' => 'CONS-FU', 'antenatal' => 'CONS-ANC', default => 'CONS-OPD',
                }]);
                $services = Service::whereIn('code', $codes)->where('is_active', true)->get();
                if ($services->isNotEmpty()) {
                    $this->billing->createInvoice($tenant, [
                        'patient_id' => $patient->id, 'visit_id' => $visit->id,
                        'customer_name' => $patient->full_name, 'customer_phone' => $patient->phone,
                    ], $services->map(fn ($s) => [
                        'item_type' => $s->category === 'registration' ? 'registration' : 'consultation',
                        'description' => $s->name, 'quantity' => 1, 'unit_price' => $s->price,
                        'tax_category' => $s->tax_category, 'reference' => $s,
                    ])->all(), $membership);
                }
            }

            $this->logger->log('patient_checked_in', $visit, ['queue' => $visit->queue_number], "Checked in {$patient->full_name} ({$visit->visit_number})");

            return $visit;
        });

        return $visit->load('patient', 'membership.payer');
    }

    /**
     * Record vital signs; obvious danger signs raise the priority automatically.
     */
    public function triage(Visit $visit, array $vitals): Visit
    {
        $this->assertOpen($visit);

        $v = array_filter($vitals, fn ($x) => $x !== null && $x !== '');
        if (! empty($v['weight']) && ! empty($v['height'])) {
            $v['bmi'] = round($v['weight'] / (($v['height'] / 100) ** 2), 1);
        }

        $alerts = [];
        if (($v['temperature'] ?? 0) >= 37.5) {
            $alerts[] = ($v['temperature'] >= 39.5 ? 'High fever' : 'Fever').' '.$v['temperature'].'°C';
        }
        if (($v['bp_systolic'] ?? 0) >= 140 || ($v['bp_diastolic'] ?? 0) >= 90) {
            $alerts[] = 'Raised BP '.($v['bp_systolic'] ?? '?').'/'.($v['bp_diastolic'] ?? '?');
        }
        if (isset($v['spo2']) && $v['spo2'] < 92) {
            $alerts[] = 'Low oxygen saturation '.$v['spo2'].'%';
        }
        if (($v['pulse'] ?? 0) > 100) {
            $alerts[] = 'Tachycardia '.$v['pulse'].'/min';
        }
        $v['alerts'] = $alerts;

        $urgent = (isset($v['spo2']) && $v['spo2'] < 92) || ($v['temperature'] ?? 0) >= 39.5 || ($v['bp_systolic'] ?? 0) >= 180;

        $visit->update([
            'vitals' => $v,
            'triaged_at' => now(),
            'priority' => $urgent ? 'urgent' : $visit->priority,
        ]);

        return $visit;
    }

    public function start(Visit $visit, User $doctor): Visit
    {
        $this->assertOpen($visit);
        $visit->update([
            'status' => 'in_consultation',
            'doctor_id' => $doctor->id,
            'seen_at' => $visit->seen_at ?? now(),
        ]);

        return $visit;
    }

    public function saveNotes(Visit $visit, array $data): Visit
    {
        $this->assertOpen($visit);
        $visit->update(array_intersect_key($data, array_flip(['chief_complaint', 'history', 'examination', 'diagnoses', 'plan'])));

        return $visit;
    }

    public function complete(Visit $visit): Visit
    {
        $this->assertOpen($visit);
        if (empty($visit->diagnoses)) {
            throw BusinessRuleException::withErrors('diagnoses', 'Record at least one diagnosis before closing the visit (needed for HMIS reporting).');
        }
        $visit->update(['status' => 'completed', 'completed_at' => now()]);
        $this->logger->log('visit_completed', $visit);

        return $visit;
    }

    public function cancel(Visit $visit, ?string $reason): Visit
    {
        $this->assertOpen($visit);
        $visit->update(['status' => 'cancelled', 'plan' => trim(($visit->plan ?? '')."\nCancelled: ".$reason)]);

        return $visit;
    }

    private function assertOpen(Visit $visit): void
    {
        if (! $visit->isOpen()) {
            throw BusinessRuleException::withErrors('status', "Visit {$visit->visit_number} is {$visit->status}.");
        }
    }
}
