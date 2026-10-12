<?php

namespace App\Services\Clinical;

use App\Exceptions\BusinessRuleException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Visit;
use App\Notifications\CriticalLabResult;
use App\Notifications\LabResultsReady;
use App\Services\ActivityLogger;
use App\Services\Billing\BillingService;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Laboratory: request -> (payment) -> specimen collected -> results with automatic flags -> verified.
 */
class LabService
{
    public function __construct(private BillingService $billing, private ActivityLogger $logger) {}

    public function order(Patient $patient, ?Visit $visit, array $testIds, string $priority = 'routine', ?string $notes = null): LabOrder
    {
        $tests = LabTest::whereIn('id', $testIds)->where('is_active', true)->get();
        if ($tests->count() !== count(array_unique($testIds))) {
            throw BusinessRuleException::withErrors('tests', 'One or more tests are not available.');
        }

        $order = DB::transaction(function () use ($patient, $visit, $tests, $priority, $notes) {
            $tenant = Auth::user()->tenant;
            $order = LabOrder::create([
                'tenant_id' => $tenant->id,
                'order_number' => DocumentNumber::next($tenant->id, 'lab'),
                'visit_id' => $visit?->id,
                'patient_id' => $patient->id,
                'ordered_by' => Auth::id(),
                'status' => 'ordered',
                'priority' => $priority,
                'clinical_notes' => $notes,
            ]);
            $items = $tests->map(fn ($t) => $order->items()->create([
                'lab_test_id' => $t->id, 'status' => 'pending', 'price' => $t->price, 'unit' => $t->unit, 'reference' => $t->referenceLabel(),
            ]));

            if ($tenant->hasModule('billing')) {
                $this->billing->createInvoice($tenant, [
                    'patient_id' => $patient->id, 'visit_id' => $visit?->id,
                    'customer_name' => $patient->full_name, 'customer_phone' => $patient->phone,
                ], $items->map(fn ($i) => [
                    'item_type' => 'lab', 'description' => 'Lab: '.$tests->firstWhere('id', $i->lab_test_id)->name,
                    'quantity' => 1, 'unit_price' => $i->price, 'tax_category' => 'exempt', 'reference' => $i,
                ])->all(), $visit?->membership);
            }

            if ($visit?->isOpen()) {
                $visit->update(['status' => 'awaiting_lab']);
            }
            $this->logger->log('lab_ordered', $order, ['tests' => $tests->pluck('code')->all()], "Lab {$order->order_number}: ".$tests->pluck('name')->implode(', '));

            return $order;
        });

        return $order->load('items.test', 'patient');
    }

    /** The invoice that charges this lab order (if billing is on). */
    public function invoiceFor(LabOrder $order): ?Invoice
    {
        $invoiceId = InvoiceItem::where('reference_type', (new LabOrderItem)->getMorphClass())
            ->whereIn('reference_id', $order->items()->pluck('id'))
            ->value('invoice_id');

        return $invoiceId ? Invoice::withoutGlobalScopes()->find($invoiceId) : null;
    }

    public function collect(LabOrder $order): LabOrder
    {
        if ($order->status !== 'ordered') {
            throw BusinessRuleException::withErrors('status', "Order {$order->order_number} is {$order->status}.");
        }

        $tenant = Auth::user()->tenant;
        $invoice = $this->invoiceFor($order);
        if ($tenant->setting('payment_before_service') && $invoice && ! $invoice->isPatientSettled()) {
            throw BusinessRuleException::withErrors('payment', 'Payment of ETB '.number_format($invoice->patientBalance(), 2)." is pending at the cashier ({$invoice->invoice_number}).");
        }

        $order->update(['status' => 'collected', 'collected_at' => now()]);

        return $order;
    }

    public function result(LabOrderItem $item, array $data): LabOrderItem
    {
        $order = $item->order;
        if (! in_array($order->status, ['collected', 'completed'], true)) {
            throw BusinessRuleException::withErrors('status', 'Collect the specimen before entering results.');
        }
        if ($item->status === 'verified') {
            throw BusinessRuleException::withErrors('status', 'Verified results cannot be changed.');
        }

        $test = $item->test;
        $flag = $this->flag($test, $data['result_value'] ?? null, $data['result_text'] ?? null);

        DB::transaction(function () use ($item, $data, $flag) {
            $item->update([
                'result_value' => $data['result_value'] ?? null,
                'result_text' => $data['result_text'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'flag' => $flag,
                'status' => 'resulted',
                'resulted_by' => Auth::id(),
                'resulted_at' => now(),
            ]);
        });

        if ($flag === 'critical') {
            $order->orderer?->notify(new CriticalLabResult($item->fresh('test', 'order.patient')));
        }

        $this->completeIfDone($order->refresh());

        return $item->refresh();
    }

    public function verify(LabOrderItem $item): LabOrderItem
    {
        if ($item->status !== 'resulted') {
            throw BusinessRuleException::withErrors('status', 'Only entered results can be verified.');
        }
        if ($item->resulted_by === Auth::id() && Auth::user()->tenant->setting('four_eyes_lab_verification')) {
            throw BusinessRuleException::withErrors('status', 'Results must be verified by a second person.');
        }
        $item->update(['status' => 'verified', 'verified_by' => Auth::id(), 'verified_at' => now()]);

        return $item;
    }

    /**
     * Automatic interpretation: numeric results against reference / critical limits;
     * qualitative results are "positive" unless they read negative / non-reactive / the first (normal) option.
     */
    public function flag(LabTest $test, mixed $value, ?string $text): ?string
    {
        if ($test->result_type === 'numeric') {
            if ($value === null || $value === '') {
                return null;
            }
            $v = (float) $value;

            return match (true) {
                $test->critical_low !== null && $v <= $test->critical_low,
                $test->critical_high !== null && $v >= $test->critical_high => 'critical',
                $test->reference_low !== null && $v < $test->reference_low => 'low',
                $test->reference_high !== null && $v > $test->reference_high => 'high',
                default => 'normal',
            };
        }

        $t = strtolower(trim((string) $text));
        if ($t === '') {
            return null;
        }
        if ($test->result_type === 'select' && ! empty($test->options)) {
            return $t === strtolower($test->options[0]) ? 'negative' : 'positive';
        }
        if ($test->result_type === 'positive_negative') {
            return preg_match('/\b(neg|negative|non[- ]?reactive|not seen|no parasite)/', $t) ? 'negative' : 'positive';
        }

        return 'normal';
    }

    private function completeIfDone(LabOrder $order): void
    {
        if ($order->status === 'completed' || $order->items()->where('status', 'pending')->exists()) {
            return;
        }

        $order->update(['status' => 'completed', 'completed_at' => now()]);
        $visit = $order->visit;
        if ($visit && $visit->status === 'awaiting_lab' && ! $visit->labOrders()->whereIn('status', ['ordered', 'collected'])->exists()) {
            $visit->update(['status' => 'awaiting_review']);
        }
        $order->orderer?->notify(new LabResultsReady($order->load('patient', 'items.test')));
    }
}
