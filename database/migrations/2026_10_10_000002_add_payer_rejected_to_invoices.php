<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The part of an insurer's share it refused to pay on a claim. It is written off, so it no longer
 * counts as money the insurer owes (payer balance = payer share - paid - rejected).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'payer_rejected')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->decimal('payer_rejected', 12, 2)->default(0)->after('payer_paid');
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('payer_rejected'));
    }
};
