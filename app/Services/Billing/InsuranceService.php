<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\Claim;
use App\Models\Invoice;
use App\Models\Payer;
use App\Models\PayerMembership;
use App\Models\Payment;
use App\Services\ActivityLogger;
use App\Support\DocumentNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Credit & insurance: claim statements, reimbursements and money owed by payers.
 */
class InsuranceService
{
    public function __construct(private BillingService $billing, private ActivityLogger $logger) {}

    /** Find valid coverage for a patient (by membership id or member number). */
    public function findCoverage(int $payerId, string $memberNumber): ?PayerMembership
    {
        return PayerMembership::with('payer')->where('payer_id', $payerId)->where('member_number', trim($memberNumber))->first();
    }

    /**
     * Collect every unclaimed, issued invoice with a payer share in the period into one claim statement.
     */
    public function generateClaim(Payer $payer, CarbonImmutable $from, CarbonImmutable $to): Claim
    {
        $invoices = Invoice::where('payer_id', $payer->id)
            ->whereNull('claim_id')
            ->where('document_type', 'invoice')
            ->whereNotIn('status', ['draft', 'void', 'awaiting_approval'])
            ->where('payer_share', '>', 0)
            ->whereBetween('issued_at', [$from->startOfDay(), $to->endOfDay()])
            ->get();

        if ($invoices->isEmpty()) {
            throw BusinessRuleException::withErrors('period', "No unclaimed {$payer->name} invoices between {$from->toDateString()} and {$to->toDateString()}.");
        }

        return DB::transaction(function () use ($payer, $from, $to, $invoices) {
            $claim = Claim::create([
                'tenant_id' => $payer->tenant_id,
                'payer_id' => $payer->id,
                'claim_number' => DocumentNumber::next($payer->tenant_id, 'claim'),
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'status' => 'draft',
                'invoices_count' => $invoices->count(),
                'total_amount' => round($invoices->sum(fn ($i) => $i->payerBalance()), 2),
                'created_by' => Auth::id(),
            ]);
            Invoice::whereKey($invoices->pluck('id'))->update(['claim_id' => $claim->id]);

            return $claim;
        });
    }

    public function submit(Claim $claim): Claim
    {
        if ($claim->status !== 'draft') {
            throw BusinessRuleException::withErrors('status', 'Only draft claims can be submitted.');
        }
        $claim->update(['status' => 'submitted', 'submitted_at' => now()]);

        return $claim;
    }

    /**
     * Payer reimbursement: spread the amount over the claim's invoices, oldest first.
     * Any amount the payer refuses becomes "rejected" on the claim.
     */
    public function recordClaimPayment(Claim $claim, float $amount, float $rejected, string $method, ?string $reference): Claim
    {
        if (! in_array($claim->status, ['submitted', 'partially_paid'], true)) {
            throw BusinessRuleException::withErrors('status', 'Submit the claim before recording payments.');
        }
        if ($amount < 0 || $rejected < 0 || $amount + $rejected - $claim->outstanding() > 0.009) {
            throw BusinessRuleException::withErrors('amount', 'Paid + rejected cannot exceed the outstanding ETB '.number_format($claim->outstanding(), 2).'.');
        }

        DB::transaction(function () use ($claim, $amount, $rejected, $method, $reference) {
            if ($amount > 0) {
                Payment::create([
                    'tenant_id' => $claim->tenant_id, 'claim_id' => $claim->id, 'payer_id' => $claim->payer_id,
                    'amount' => $amount, 'method' => $method, 'reference' => $reference,
                    'received_by' => Auth::id(), 'paid_at' => now(), 'notes' => "Reimbursement for {$claim->claim_number}",
                ]);
            }

            $remaining = $amount;
            foreach ($claim->invoices()->orderBy('issued_at')->get() as $invoice) {
                $take = min($remaining, $invoice->payerBalance());
                if ($take <= 0) {
                    continue;
                }
                $invoice->increment('payer_paid', round($take, 2));
                $this->billing->refreshStatus($invoice->refresh());
                $remaining = round($remaining - $take, 2);
            }

            // What the payer refused is written off on the invoices it covered, so it stops counting as owed
            $toReject = $rejected;
            foreach ($claim->invoices()->orderBy('issued_at')->get() as $invoice) {
                $take = min($toReject, $invoice->payerBalance());
                if ($take <= 0) {
                    continue;
                }
                $invoice->increment('payer_rejected', round($take, 2));
                $this->billing->refreshStatus($invoice->refresh());
                $toReject = round($toReject - $take, 2);
            }

            $paid = round((float) $claim->paid_amount + $amount, 2);
            $rejectedTotal = round((float) $claim->rejected_amount + $rejected, 2);
            $settled = $paid + $rejectedTotal >= (float) $claim->total_amount - 0.009;
            $claim->update([
                'paid_amount' => $paid,
                'rejected_amount' => $rejectedTotal,
                'status' => $settled ? ($paid > 0 ? 'paid' : 'rejected') : 'partially_paid',
                'paid_at' => $settled ? now() : null,
            ]);
            $this->logger->log('claim_payment', $claim, ['paid' => $amount, 'rejected' => $rejected, 'reference' => $reference]);
        });

        return $claim->refresh();
    }

    /**
     * Receivables owed by each payer, bucketed by invoice age (0-30, 31-60, 61-90, 90+ days).
     */
    public function aging(): array
    {
        $rows = Invoice::query()
            ->with('payer:id,name,type,credit_limit')
            ->where('document_type', 'invoice')
            ->whereNotIn('status', ['draft', 'void'])
            ->whereNotNull('payer_id')
            ->whereRaw('payer_paid + payer_rejected < payer_share - 0.009') // rejected amounts are written off, not owed
            ->get()
            ->groupBy('payer_id')
            ->map(function ($invoices) {
                $buckets = ['0_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0];
                foreach ($invoices as $invoice) {
                    $age = (int) $invoice->issued_at->diffInDays(now());
                    $key = match (true) {
                        $age <= 30 => '0_30', $age <= 60 => '31_60', $age <= 90 => '61_90', default => '90_plus'
                    };
                    $buckets[$key] += $invoice->payerBalance();
                }
                $payer = $invoices->first()->payer;
                $total = round(array_sum($buckets), 2);

                return [
                    'payer' => ['id' => $payer->id, 'name' => $payer->name, 'type' => $payer->type],
                    'buckets' => array_map(fn ($v) => round($v, 2), $buckets),
                    'total' => $total,
                    'invoices' => $invoices->count(),
                    'credit_limit' => $payer->credit_limit,
                    'over_limit' => $payer->credit_limit && $total > $payer->credit_limit,
                ];
            })
            ->sortByDesc('total')
            ->values();

        $patients = Invoice::query()
            ->where('document_type', 'invoice')
            ->whereNotIn('status', ['draft', 'void'])
            ->whereColumn('amount_paid', '<', 'patient_share')
            ->selectRaw('count(*) as invoices, coalesce(sum(patient_share - amount_paid), 0) as due')
            ->first();

        return [
            'payers' => $rows,
            'payer_total' => round($rows->sum('total'), 2),
            'patient_total' => round((float) $patients->due, 2),
            'patient_invoices' => (int) $patients->invoices,
        ];
    }
}
