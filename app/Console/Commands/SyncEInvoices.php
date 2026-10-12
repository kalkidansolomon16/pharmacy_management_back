<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\Fiscal\FiscalService;
use Illuminate\Console\Command;

class SyncEInvoices extends Command
{
    protected $signature = 'einvoice:sync {--limit=200}';

    protected $description = 'Register invoices that are still pending with the Ministry of Revenue (offline / failed earlier)';

    public function handle(FiscalService $fiscal): int
    {
        $pending = Invoice::withoutGlobalScopes()
            ->where('fiscal_status', 'pending')
            ->where('fiscal_attempts', '<', config('einvoice.max_attempts'))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $accepted = $pending->filter(fn ($invoice) => $fiscal->register($invoice)->fiscal_status === 'accepted')->count();
        $this->info("Registered {$accepted} of {$pending->count()} pending invoices.");

        return self::SUCCESS;
    }
}
