<?php

namespace App\Services\Reports;

use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Billing view of the organization: what was invoiced, collected, owed and declared as tax.
 */
class FinancialReportService
{
    public function __construct(private ReportService $reports) {}

    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $invoices = Invoice::query()->where('document_type', 'invoice')->where('status', '!=', 'void')->whereBetween('issued_at', [$from, $to]);
        $payments = Payment::query()->whereBetween('paid_at', [$from, $to]);

        $totals = (clone $invoices)->selectRaw('count(*) as n, coalesce(sum(total),0) as total, coalesce(sum(tax_total),0) as tax,
            coalesce(sum(patient_share),0) as patient, coalesce(sum(payer_share),0) as payer, coalesce(sum(discount),0) as discount')->first();

        $ids = (clone $invoices)->select('id');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'invoices' => (int) $totals->n,
                'invoiced' => round((float) $totals->total, 2),
                'tax_collected' => round((float) $totals->tax, 2),
                'patient_share' => round((float) $totals->patient, 2),
                'payer_share' => round((float) $totals->payer, 2),
                'discounts' => round((float) $totals->discount, 2),
                'collected' => round((float) (clone $payments)->sum('amount'), 2),
                'voided' => Invoice::query()->where('status', 'void')->whereBetween('voided_at', [$from, $to])->count(),
            ],
            'by_department' => DB::table('invoice_items')->whereIn('invoice_id', $ids)->groupBy('item_type')
                ->selectRaw('item_type, count(*) as lines, sum(line_total) as revenue, sum(tax_amount) as tax')->orderByDesc('revenue')->get()
                ->map(fn ($r) => ['department' => $r->item_type, 'lines' => (int) $r->lines, 'revenue' => round((float) $r->revenue, 2), 'tax' => round((float) $r->tax, 2)]),
            'by_payment_method' => (clone $payments)->groupBy('method')->selectRaw('method, count(*) as n, sum(amount) as amount')->orderByDesc('amount')->get()
                ->map(fn ($r) => ['method' => $r->method, 'count' => (int) $r->n, 'amount' => round((float) $r->amount, 2)]),
            'by_cashier' => (clone $payments)->whereNotNull('received_by')->join('users', 'users.id', '=', 'payments.received_by')
                ->groupBy('users.id', 'users.name')->selectRaw('users.name, count(*) as n, sum(payments.amount) as amount')->orderByDesc('amount')->get()
                ->map(fn ($r) => ['cashier' => $r->name, 'count' => (int) $r->n, 'amount' => round((float) $r->amount, 2)]),
            'fiscal' => Invoice::query()->whereBetween('issued_at', [$from, $to])->groupBy('fiscal_status')
                ->selectRaw('fiscal_status, count(*) as n')->pluck('n', 'fiscal_status'),
            'daily' => $this->reports->dailySeries(clone $invoices, 'issued_at', $from, $to, 'sum(total)'),
        ];
    }

    /** Invoices list for export. */
    public function rows(CarbonImmutable $from, CarbonImmutable $to)
    {
        return Invoice::query()->with('payer:id,name')->where('status', '!=', 'void')->whereBetween('issued_at', [$from, $to])->orderBy('issued_at')->get()
            ->map(fn ($i) => [
                'date' => $i->issued_at->format('Y-m-d H:i'),
                'number' => $i->invoice_number,
                'type' => $i->document_type === 'credit_note' ? 'Credit note' : 'Invoice',
                'customer' => $i->customer_name,
                'payer' => $i->payer?->name ?? 'Cash',
                'subtotal' => number_format((float) $i->subtotal, 2),
                'tax' => number_format((float) $i->tax_total, 2),
                'total' => number_format((float) $i->total, 2),
                'status' => ucwords(str_replace('_', ' ', $i->status)),
                'irn' => $i->irn ? substr($i->irn, 0, 12).'…' : '',
                'rrn' => $i->rrn ?? ucfirst($i->fiscal_status),
            ]);
    }
}
