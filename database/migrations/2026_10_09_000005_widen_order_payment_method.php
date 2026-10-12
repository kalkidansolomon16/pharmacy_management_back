<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment methods grow over time (M-Pesa, corporate credit...): store them as a string instead of an enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 30)->default('cash')->change();
        });
    }

    public function down(): void
    {
        // Intentionally left as a string: narrowing back could fail on existing rows.
    }
};
