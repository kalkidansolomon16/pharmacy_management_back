<?php

namespace App\Services\Fiscal;

use Illuminate\Support\Str;

/**
 * Local stand-in for the Ministry's system: applies the same structural checks and returns an RRN.
 * Lets the whole invoicing flow (including retries) run without credentials.
 */
class SandboxEInvoiceGateway implements EInvoiceGateway
{
    public function register(array $payload): array
    {
        if (mt_rand() / mt_getrandmax() < (float) config('einvoice.sandbox_failure_rate')) {
            throw new EInvoiceUnavailable('Sandbox: simulated network failure.');
        }

        if (! preg_match('/^\d{10}$/', (string) ($payload['seller']['tin'] ?? ''))) {
            throw new EInvoiceRejected('Seller TIN must be 10 digits.');
        }

        $lines = collect($payload['items'] ?? []);
        $sum = round($lines->sum('line_total'), 2);
        if (abs($sum - round((float) $payload['totals']['grand_total'] + (float) ($payload['totals']['discount'] ?? 0), 2)) > 0.05) {
            throw new EInvoiceRejected("Line totals ({$sum}) do not match the invoice total.");
        }

        return [
            'rrn' => 'RRN'.now()->format('ymd').strtoupper(Str::random(10)),
            'registered_at' => now()->toIso8601String(),
            'status' => 'ACCEPTED',
            'environment' => 'sandbox',
        ];
    }

    public function name(): string
    {
        return 'sandbox';
    }
}
