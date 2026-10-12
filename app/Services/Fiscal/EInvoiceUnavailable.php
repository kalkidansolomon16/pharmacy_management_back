<?php

namespace App\Services\Fiscal;

use RuntimeException;

/** Temporary failure (offline, timeout, 5xx): keep the invoice pending and retry. */
class EInvoiceUnavailable extends RuntimeException {}
