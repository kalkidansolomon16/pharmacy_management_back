<?php

namespace App\Services\Fiscal;

use RuntimeException;

/** The Ministry refused the document (invalid TIN, totals mismatch...): needs human correction. */
class EInvoiceRejected extends RuntimeException {}
