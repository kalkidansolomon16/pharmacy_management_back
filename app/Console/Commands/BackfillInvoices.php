<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Billing\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * One-off upgrade step: sales recorded before billing existed get an invoice so revenue, owner and
 * financial reports cover the full history. These are marked "not_required" (they predate
 * e-invoicing) and are never sent to the Ministry of Revenue.
 */
class BackfillInvoices extends Command
{
    protected $signature = 'billing:backfill-invoices {--tenant= : Only this tenant id}';

    protected $description = 'Create invoices for completed sales that were recorded before billing was enabled';

    public function handle(BillingService $billing): int
    {
        $tenants = Tenant::where(fn ($q) => $q->where('type', 'pharmacy')->orWhereJsonContains('modules', 'pharmacy'))
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->get();
        $created = 0;

        foreach ($tenants as $tenant) {
            $orders = Order::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'completed')
                ->whereDoesntHave('invoice')->orderBy('fulfilled_at')->get();
            if ($orders->isEmpty()) {
                continue;
            }

            $actor = User::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('id')->first();
            if (! $actor) {
                continue;
            }
            Auth::setUser($actor);

            // Suspend e-invoicing while back-filling: historical sales are not fiscal documents
            $settings = $tenant->settings;
            $tenant->update(['settings' => array_merge($settings ?? [], ['einvoice_enabled' => false])]);

            try {
                ActivityLogger::muted(function () use ($orders, $billing, &$created) {
                    foreach ($orders as $order) {
                        $invoice = $billing->invoiceForOrder($order);
                        $at = $order->fulfilled_at ?? $order->created_at;
                        Invoice::withoutGlobalScopes()->whereKey($invoice->id)->update(['issued_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
                        $invoice->payments()->update(['paid_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
                        $created++;
                    }
                });
            } finally {
                $tenant->update(['settings' => $settings]);
            }
            $this->line("{$tenant->name}: {$orders->count()} invoices");
        }

        Auth::forgetUser();
        $this->info("Back-filled {$created} invoices.");

        return self::SUCCESS;
    }
}
