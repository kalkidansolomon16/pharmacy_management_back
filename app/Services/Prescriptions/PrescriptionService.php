<?php

namespace App\Services\Prescriptions;

use App\Exceptions\BusinessRuleException;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Notifications\PrescriptionIssued;
use App\Services\ActivityLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;

class PrescriptionService
{
    public const DEFAULT_VALIDITY_DAYS = 30;

    public function __construct(private ActivityLogger $logger) {}

    public function issue(User $doctor, Patient $patient, array $data): Prescription
    {
        $medicineIds = collect($data['items'])->pluck('medicine_id');
        if ($medicineIds->duplicates()->isNotEmpty()) {
            throw BusinessRuleException::withErrors('items', 'Each medicine may appear only once per prescription.');
        }

        $inactive = Medicine::whereIn('id', $medicineIds)->where('is_active', false)->pluck('generic_name');
        if ($inactive->isNotEmpty()) {
            throw BusinessRuleException::withErrors('items', 'Inactive medicines cannot be prescribed: '.$inactive->implode(', '));
        }

        $prescription = DB::transaction(function () use ($doctor, $patient, $data) {
            $prescription = Prescription::create([
                'tenant_id' => $doctor->tenant_id,
                'doctor_id' => $doctor->id,
                'patient_id' => $patient->id,
                'reference_code' => ReferenceGenerator::prescriptionCode(),
                'diagnosis' => $data['diagnosis'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'active',
                'issued_at' => now(),
                'expires_at' => now()->addDays((int) ($data['validity_days'] ?? self::DEFAULT_VALIDITY_DAYS))->endOfDay(),
            ]);

            foreach ($data['items'] as $item) {
                $prescription->items()->create([
                    'medicine_id' => $item['medicine_id'],
                    'dosage' => $item['dosage'],
                    'frequency' => $item['frequency'],
                    'duration_days' => $item['duration_days'],
                    'total_quantity' => $item['total_quantity'],
                    'instructions' => $item['instructions'] ?? null,
                ]);
            }

            $this->logger->log('prescription_issued', $prescription, [
                'reference_code' => $prescription->reference_code,
                'items' => count($data['items']),
            ], "Issued prescription {$prescription->reference_code} for {$patient->full_name}");

            return $prescription;
        });

        $patient->user?->notify(new PrescriptionIssued($prescription));

        return $prescription->load('items.medicine', 'patient', 'doctor');
    }

    /**
     * Look up a prescription by its code across all hospitals (pharmacies dispense other tenants' prescriptions).
     */
    public function findByCode(string $code): Prescription
    {
        $prescription = Prescription::acrossTenants()
            ->with(['items.medicine', 'patient', 'doctor:id,name,license_number', 'hospital:id,name,city,phone'])
            ->where('reference_code', strtoupper(trim($code)))
            ->first();

        if (! $prescription) {
            throw BusinessRuleException::withErrors('prescription_code', 'No prescription found with that reference code.');
        }

        return $prescription;
    }

    /**
     * Resolve a prescription that can still be dispensed, optionally verifying the patient's phone.
     */
    public function findDispensable(string $code, ?string $phone = null): Prescription
    {
        $prescription = $this->findByCode($code);
        $this->assertDispensable($prescription);

        if ($phone !== null && ReferenceGenerator::normalizePhone($phone) !== ReferenceGenerator::normalizePhone($prescription->patient->phone)) {
            throw BusinessRuleException::withErrors('patient_phone', 'The phone number does not match the patient on this prescription.');
        }

        return $prescription;
    }

    public function assertDispensable(Prescription $prescription): void
    {
        if ($prescription->isDispensable()) {
            return;
        }

        if ($prescription->isExpired() && in_array($prescription->status, Prescription::DISPENSABLE_STATUSES, true)) {
            $prescription->update(['status' => 'expired']);
        }

        $reason = match (true) {
            $prescription->status === 'cancelled' => 'was cancelled by the prescriber',
            $prescription->status === 'dispensed' => 'has already been fully dispensed',
            default => 'expired on '.$prescription->expires_at->toDateString(),
        };

        throw BusinessRuleException::withErrors('prescription_code', "Prescription {$prescription->reference_code} {$reason}.");
    }

    /**
     * Increase the dispensed quantity of a line, never beyond what was prescribed.
     */
    public function recordDispense(PrescriptionItem $item, int $quantity): void
    {
        $locked = PrescriptionItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

        if ($quantity > $locked->remainingQuantity()) {
            throw BusinessRuleException::withErrors('quantity', "Prescription allows only {$locked->remainingQuantity()} more units of this medicine.");
        }

        $locked->increment('dispensed_quantity', $quantity);
    }

    public function refreshStatus(Prescription $prescription): void
    {
        $items = $prescription->items()->get();

        $status = match (true) {
            $items->every(fn ($i) => $i->remainingQuantity() === 0) => 'dispensed',
            $items->contains(fn ($i) => $i->dispensed_quantity > 0) => 'partially_dispensed',
            default => 'active',
        };

        $prescription->update(['status' => $status]);
    }

    public function cancel(Prescription $prescription, ?string $reason = null): Prescription
    {
        if (! in_array($prescription->status, Prescription::DISPENSABLE_STATUSES, true)) {
            throw BusinessRuleException::withErrors('status', 'Only active prescriptions can be cancelled.');
        }

        $prescription->update(['status' => 'cancelled', 'notes' => trim($prescription->notes."\nCancelled: ".$reason)]);
        $this->logger->log('prescription_cancelled', $prescription, ['reason' => $reason]);

        return $prescription;
    }

    public function expireOverdue(): int
    {
        return Prescription::acrossTenants()
            ->whereIn('status', Prescription::DISPENSABLE_STATUSES)
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }
}
