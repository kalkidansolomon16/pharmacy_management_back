<?php

namespace App\Services\Fiscal;

use App\Models\Invoice;
use App\Support\EthiopianCalendar;
use Illuminate\Support\Facades\Log;

/**
 * Registers invoices with the Ministry of Revenue (IRN + RRN + QR) and keeps unregistered ones queued
 * so selling never stops when the internet does: the receipt prints "pending registration" and the
 * scheduled `einvoice:sync` command completes registration once the connection returns.
 */
class FiscalService
{
    public function __construct(private EInvoiceGateway $gateway) {}

    /** Mark an invoice for registration and compute its IRN (deterministic, so retries never duplicate). */
    public function prepare(Invoice $invoice): void
    {
        $seller = $invoice->seller;
        if (! $seller?->setting('einvoice_enabled')) {
            $invoice->forceFill(['fiscal_status' => 'not_required'])->save();

            return;
        }

        $invoice->forceFill([
            'irn' => $this->irn($invoice),
            'fiscal_status' => 'pending',
            'fiscal_payload' => $this->payload($invoice),
        ])->save();
        $invoice->forceFill(['fiscal_qr' => $this->qr($invoice)])->save();
    }

    /** Try to register now. Never throws: failures are recorded on the invoice. */
    public function register(Invoice $invoice): Invoice
    {
        if (! in_array($invoice->fiscal_status, ['pending', 'failed'], true)) {
            return $invoice;
        }

        $invoice->increment('fiscal_attempts');

        try {
            $result = $this->gateway->register($invoice->fiscal_payload ?? $this->payload($invoice));
            $invoice->forceFill([
                'rrn' => $result['rrn'],
                'fiscal_status' => 'accepted',
                'fiscal_response' => $result,
                'fiscal_error' => null,
                'fiscal_registered_at' => now(),
            ])->save();
            $invoice->forceFill(['fiscal_qr' => $this->qr($invoice)])->save();
        } catch (EInvoiceRejected $e) {
            $invoice->forceFill(['fiscal_status' => 'failed', 'fiscal_error' => $e->getMessage()])->save();
        } catch (\Throwable $e) {
            $invoice->forceFill(['fiscal_status' => 'pending', 'fiscal_error' => $e->getMessage()])->save();
            Log::warning("E-invoice {$invoice->invoice_number} not registered yet: {$e->getMessage()}");
        }

        return $invoice;
    }

    /**
     * Invoice Reference Number: SHA-256 over seller TIN, document type, number and fiscal year.
     * Unique per document and reproducible, so a retried submission carries the same IRN.
     */
    public function irn(Invoice $invoice): string
    {
        $tin = $invoice->seller->tin_number ?? '0000000000';
        $year = EthiopianCalendar::fiscalYear($invoice->issued_at ?? now());

        return strtoupper(hash('sha256', implode('|', [$tin, $invoice->document_type, $invoice->invoice_number, $year])));
    }

    /** Text encoded in the receipt QR code: enough for an inspector to verify the document offline. */
    public function qr(Invoice $invoice): string
    {
        return implode(';', [
            'TIN:'.($invoice->seller->tin_number ?? ''),
            'INV:'.$invoice->invoice_number,
            'IRN:'.$invoice->irn,
            'RRN:'.($invoice->rrn ?? 'PENDING'),
            'DATE:'.($invoice->issued_at ?? now())->format('Y-m-d\TH:i'),
            'TOTAL:'.number_format((float) $invoice->total, 2, '.', ''),
            'TAX:'.number_format((float) $invoice->tax_total, 2, '.', ''),
        ]);
    }

    public function payload(Invoice $invoice): array
    {
        $invoice->loadMissing('items', 'seller');
        $seller = $invoice->seller;

        return [
            'document_type' => $invoice->document_type === 'credit_note' ? 'CREDIT_NOTE' : 'INVOICE',
            'invoice_number' => $invoice->invoice_number,
            'irn' => $invoice->irn ?? $this->irn($invoice),
            'issue_date' => ($invoice->issued_at ?? now())->toIso8601String(),
            'currency' => 'ETB',
            'seller' => [
                'tin' => $seller->tin_number,
                'vat_number' => $seller->vat_number,
                'name' => $seller->name,
                'tax_regime' => $seller->tax_regime,
                'address' => trim(implode(', ', array_filter([$seller->address_line, $seller->sub_city, $seller->city, $seller->region]))),
            ],
            'buyer' => [
                'name' => $invoice->customer_name,
                'tin' => $invoice->customer_tin,
                'phone' => $invoice->customer_phone,
            ],
            'items' => $invoice->items->map(fn ($i) => [
                'description' => $i->description,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'tax_category' => $i->tax_category,
                'tax_rate' => (float) $i->tax_rate,
                'tax_amount' => (float) $i->tax_amount,
                'line_total' => (float) $i->line_total,
            ])->values()->all(),
            'totals' => [
                'subtotal' => (float) $invoice->subtotal,
                'discount' => (float) $invoice->discount,
                'tax_total' => (float) $invoice->tax_total,
                'grand_total' => (float) $invoice->total,
            ],
        ];
    }
}
