<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why an organization was rejected or suspended (shown to its team and in the email),
 * and when its status last changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'status_reason')) {
                $table->text('status_reason')->nullable()->after('status');
            }
            if (! Schema::hasColumn('tenants', 'status_changed_at')) {
                $table->timestamp('status_changed_at')->nullable()->after('status_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['status_reason', 'status_changed_at']);
        });
    }
};
