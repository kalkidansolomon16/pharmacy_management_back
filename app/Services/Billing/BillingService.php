<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Jobs\RegisterFiscalInvoice;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Order;
use App\Models\PayerAuthorization;
use App\Models\PayerMembership;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AuthorizationRequested;
use App\Services\ActivityLogger;
use App\Services\Fiscal\FiscalService;
use App\Support\DocumentNumber;
use App\Support\TaxCalculator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Invoices for every charge (registration, consultation, lab, medicines), split between the patient
 * and an insurer / employer, registered with the Ministry of Revenue.
 */
class BillingService
{
    public function __construct(private FiscalService $fiscal, private ActivityLogger $logger) {}

    /**
     * @param  array<int, array{item_type: string, description: string, quantity: float|int, unit_price: float|string,
     *                          tax_category?: string, reference?: \Illuminate\Database\Eloquent\Model, medicine?: ?Medicine}>  $lines
     */
    public function createInvoice(Tenant $seller, array $attributes, array $lines, ?PayerMembership $membership = null, float $discount = 0): Invoice
    {
        if (! $lines) {
            throw BusinessRuleException::withErrors('items', 'An invoice needs at least one line.');
        }

        $membership = $membership?->isValid() ? $membership : null;

        $invoice = DB::transaction(function () use ($seller, $attributes, $lines, $membership, $discount) {
            $invoice = Invoice::create($attributes + [
                'tenant_id' => $seller->id,
                'invoice_number' => DocumentNumber::next($seller->id, 'invoice'),
                'document_type' => 'invoice',
                'status' => 'draft',
                'payer_id' => $membership?->payer_id,
                'membership_id' => $membership?->id,
                'created_by' => Auth::id(),
                'issued_at' => now(),
            ]);

            $subtotal = $taxTotal = $payerShare = 0.0;
            foreach ($lines as $line) {
                $net = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
                $taxCategory = $line['tax_category'] ?? 'exempt';
                $tax = TaxCalculator::line($seller, $net, $taxCategory, $line['item_type'] !== 'medicine');
                [$covered, $payerAmount] = $this->coverage($membership, $line['item_type'], $tax['total'], $line['medicine'] ?? null);

                $invoice->items()->create([
                    'item_type' => $line['item_type'],
                    'reference_type' => isset($line['reference']) ? $line['reference']->getMorphClass() : null,
                    'reference_id' => isset($line['reference']) ? $line['reference']->getKey() : null,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'tax_category' => $taxCategory,
                    'tax_rate' => $tax['rate'],
                    'tax_amount' => $tax['tax'],
                    'line_total' => $tax['total'],
                    'covered' => $covered,
                    'payer_amount' => $payerAmount,
                    'patient_amount' => round($tax['total'] - $payerAmount, 2),
                ]);

                $subtotal += $net;
                $taxTotal += $tax['tax'];
                $payerShare += $payerAmount;
            }

            $total = round($subtotal + $taxTotal - $discount, 2);
            $patientShare = max(0, round($total - $payerShare, 2));
            $payer = $membership?->payer;
            $needsApproval = $payer && $payer->preauth_threshold && $payerShare > $payer->preauth_threshold;

            $invoice->update([
                'subtotal' => round($subtotal, 2),
                'discount' => $discount,
                'tax_total' => round($taxTotal, 2),
                'total' => $total,
                'payer_share' => round($payerShare, 2),
                'patient_share' => $patientShare,
                'status' => $needsApproval ? 'awaiting_approval' : 'issued',
                'due_at' => $payer ? now()->addDays($payer->payment_terms_days)->toDateString() : null,
            ]);

            if ($needsApproval) {
                PayerAuthorization::create([
                    'tenant_id' => $seller->id,
                    'invoice_id' => $invoice->id,
                    'payer_id' => $payer->id,
                    'amount' => round($payerShare, 2),
                    'requested_by' => Auth::id(),
                ]);
            }

            $this->refreshStatus($invoice);
            $this->fiscal->prepare($invoice->fresh('seller', 'items'));

            return $invoice;
        });

        if ($invoice->fresh()->fiscal_status === 'pending') {
            RegisterFiscalInvoice::dispatch($invoice->id);
        }

        // Insurance desk staff are asked to obtain the payer's approval
        $authorization = $invoice->authorizations()->where('status', 'pending')->first();
        if ($authorization) {
            User::where('tenant_id', $seller->id)->where('status', 'active')->permission('insurance.claims')->get()
                ->each->notify(new AuthorizationRequested($authorization->load('payer', 'invoice')));
        }

        return $invoice->fresh(['items', 'payer', 'membership']);
    }

    /**
     * How much of a line the payer covers: nothing without valid coverage, an excluded category,
     * or an uncovered service type; otherwise everything except the patient's co-pay percentage.
     *
     * @return array{0: bool, 1: float}
     */
    public function coverage(?PayerMembership $membership, string $itemType, float $lineTotal, ?Medicine $medicine = null): array
    {
        if (! $membership) {
            return [false, 0.0];
        }

        $payer = $membership->payer;
        if (! $payer->covers($itemType)) {
            return [false, 0.0];
        }
        if ($medicine && in_array($medicine->category_id, $payer->excluded_category_ids ?? [], false)) {
            return [false, 0.0];
        }

        return [true, round($lineTotal * (1 - $membership->copayPercent() / 100), 2)];
    }

    /**
     * Turn a dispensed pharmacy order into its fiscal invoice (one receipt per sale).
     */
    public function invoiceForOrder(Order $order): Invoice
    {
        $order->loadMissing('items.medicine', 'pharmacy', 'membership.payer', 'prescription.patient');

        $lines = $order->items->filter(fn ($i) => $i->fulfilled_quantity > 0)->map(fn ($i) => [
            'item_type' => 'medicine',
            'description' => $i->medicine->displayName(),
            'quantity' => $i->fulfilled_quantity,
            'unit_price' => $i->unit_price,
            'tax_category' => $i->medicine->tax_category ?? 'exempt',
            'reference' => $i,
            'medicine' => $i->medicine,
        ])->values()->all();

        $invoice = $this->createInvoice($order->pharmacy, [
            'order_id' => $order->id,
            'patient_id' => $order->prescription?->patient_id,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
        ], $lines, $order->membership, (float) $order->discount);

        // The patient's share is collected at the counter / on delivery
        if ($order->payment_status === 'paid' && $invoice->patientBalance() > 0) {
            $this->recordPayment($invoice, $invoice->patientBalance(), $order->payment_method, null, 'Collected with sale '.$order->order_number);
        }

        return $invoice->fresh(['items', 'payments']);
    }

    public function recordPayment(Invoice $invoice, float $amount, string $method, ?string $reference = null, ?string $notes = null): Payment
    {
        if ($invoice->status === 'void') {
            throw BusinessRuleException::withErrors('invoice', 'This invoice has been voided.');
        }
        $due = $invoice->patientBalance();
        if ($amount <= 0 || $amount - $due > 0.009) {
            throw BusinessRuleException::withErrors('amount', 'Amount must be between 0.01 and the balance due (ETB '.number_format($due, 2).').');
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $notes) {
            $payment = Payment::create([
                'tenant_id' => $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'amount' => round($amount, 2),
                'method' => $method,
                'reference' => $reference,
                'notes' => $notes,
                'received_by' => Auth::id(),
                'paid_at' => now(),
            ]);
            $invoice->increment('amount_paid', round($amount, 2));
            $this->refreshStatus($invoice->refresh());

            return $payment;
        });
    }

    public function refreshStatus(Invoice $invoice): void
    {
        if (in_array($invoice->status, ['void', 'draft', 'awaiting_approval'], true)) {
            return;
        }

        $patientDue = $invoice->patientBalance();
        $payerDue = $invoice->payerBalance();
        $status = match (true) {
            $patientDue <= 0.009 && $payerDue <= 0.009 => 'paid',
            (float) $invoice->amount_paid > 0 || (float) $invoice->payer_paid > 0 => 'partially_paid',
            default => 'issued',
        };

        if ($status !== $invoice->status) {
            $invoice->update(['status' => $status]);
        }
    }

    /**
     * Payer decision on a pre-authorization. On rejection the patient becomes responsible for the full amount.
     */
    public function decideAuthorization(PayerAuthorization $authorization, bool $approved, ?string $reference, ?string $notes): PayerAuthorization
    {
        if ($authorization->status !== 'pending') {
            throw BusinessRuleException::withErrors('status', 'This request was already decided.');
        }

        DB::transaction(function () use ($authorization, $approved, $reference, $notes) {
            $authorization->update([
                'status' => $approved ? 'approved' : 'rejected',
                'reference_number' => $reference,
                'notes' => $notes,
                'decided_by' => Auth::id(),
                'decided_at' => now(),
            ]);

            $invoice = $authorization->invoice;
            if (! $approved) {
                $invoice->items()->update(['covered' => false, 'payer_amount' => 0, 'patient_amount' => DB::raw('line_total')]);
                $invoice->update(['payer_share' => 0, 'patient_share' => $invoice->total, 'payer_id' => null, 'membership_id' => null]);
            }
            $invoice->update(['status' => 'issued']);
            $this->refreshStatus($invoice->refresh());
        });

        return $authorization->refresh();
    }

    /**
     * Void an unpaid invoice. Once registered with the Ministry, a credit note is issued instead of silent deletion.
     */
    public function void(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status === 'void') {
            throw BusinessRuleException::withErrors('status', 'Already void.');
        }
        if ((float) $invoice->amount_paid > 0 || (float) $invoice->payer_paid > 0) {
            throw BusinessRuleException::withErrors('status', 'Payments were received on this invoice: refund them before voiding.');
        }
        if ($invoice->claim_id) {
            throw BusinessRuleException::withErrors('status', 'This invoice is part of an insurance claim.');
        }

        $credit = null;
        DB::transaction(function () use ($invoice, $reason, &$credit) {
            $invoice->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => $reason]);
            $invoice->authorizations()->where('status', 'pending')->update(['status' => 'rejected', 'notes' => 'Invoice voided']);

            if ($invoice->fiscal_status === 'accepted') {
                $credit = $this->creditNote($invoice, $reason);
            } elseif ($invoice->fiscal_status === 'pending') {
                $invoice->update(['fiscal_status' => 'not_required']); // never reached the Ministry
            }
            $this->logger->log('invoice_voided', $invoice, ['reason' => $reason, 'credit_note' => $credit?->invoice_number]);
        });

        if ($credit) {
            RegisterFiscalInvoice::dispatch($credit->id);
        }

        return $invoice->refresh();
    }

    private function creditNote(Invoice $original, string $reason): Invoice
    {
        $credit = $original->replicate(['irn', 'rrn', 'fiscal_qr', 'fiscal_payload', 'fiscal_response', 'fiscal_registered_at']);
        $credit->fill([
            'invoice_number' => DocumentNumber::next($original->tenant_id, 'credit_note'),
            'document_type' => 'credit_note',
            'status' => 'issued',
            'void_reason' => "Credit for {$original->invoice_number}: {$reason}",
            'patient_share' => 0, // a credit note reverses the original; nobody owes anything on it
            'payer_share' => 0,
            'claim_id' => null,
            'amount_paid' => 0,
            'payer_paid' => 0,
            'issued_at' => now(),
            'fiscal_attempts' => 0,
            'fiscal_error' => null,
            'created_by' => Auth::id(),
        ])->save();

        foreach ($original->items as $item) {
            $credit->items()->create($item->only([
                'item_type', 'reference_type', 'reference_id', 'description', 'quantity', 'unit_price', 'tax_category',
                'tax_rate', 'tax_amount', 'line_total', 'covered', 'payer_amount', 'patient_amount',
            ]));
        }
        $this->fiscal->prepare($credit->fresh('seller', 'items'));

        return $credit;
    }
}
