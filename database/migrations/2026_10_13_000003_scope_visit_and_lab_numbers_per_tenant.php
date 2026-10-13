<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visit and lab order numbers are counted per hospital (V-2019-00001 at every hospital),
 * so they must be unique per hospital, not across the whole platform - like invoices and claims already are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropUnique(['visit_number']);
            $table->unique(['tenant_id', 'visit_number']);
        });
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dropUnique(['order_number']);
            $table->unique(['tenant_id', 'order_number']);
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'visit_number']);
            $table->unique('visit_number');
        });
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'order_number']);
            $table->unique('order_number');
        });
    }
};
