<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Fiscal\FiscalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RegisterFiscalInvoice implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $invoiceId)
    {
        $this->afterCommit();
    }

    public function handle(FiscalService $fiscal): void
    {
        $invoice = Invoice::withoutGlobalScopes()->find($this->invoiceId);
        if ($invoice) {
            $fiscal->register($invoice);
        }
    }
}
