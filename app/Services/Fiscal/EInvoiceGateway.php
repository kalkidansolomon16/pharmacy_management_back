<?php

namespace App\Services\Fiscal;

/**
 * Transport to the Ministry of Revenue Electronic Invoice Registration System.
 */
interface EInvoiceGateway
{
    /**
     * Register an invoice. Returns at least ['rrn' => string, 'registered_at' => ISO-8601].
     *
     * @throws EInvoiceUnavailable when the service cannot be reached (the invoice is retried later)
     * @throws EInvoiceRejected when the Ministry rejects the document (needs correction, not retried)
     */
    public function register(array $payload): array;

    public function name(): string;
}
