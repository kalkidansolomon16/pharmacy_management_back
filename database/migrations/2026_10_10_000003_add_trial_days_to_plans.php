<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Free trial per plan: new organizations use the plan free for this many days (0 = no free trial). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'trial_days')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->unsignedSmallInteger('trial_days')->default(30)->after('price_monthly');
            });
        }
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('trial_days'));
    }
};
