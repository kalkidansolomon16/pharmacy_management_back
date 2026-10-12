<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmacies (with the catalog.contribute permission) may add medicines and categories.
 * Their entries start as "pending" (usable by that organization only) until the platform team
 * approves them for the shared catalogue. Existing rows are platform-made, so they stay "approved".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('medicines', 'review_status')) {
            Schema::table('medicines', function (Blueprint $table) {
                $table->foreignId('created_by_tenant_id')->nullable()->after('is_active')->constrained('tenants')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->after('created_by_tenant_id')->constrained('users')->nullOnDelete();
                $table->string('review_status', 10)->default('approved')->after('created_by'); // approved | pending | rejected
                $table->string('review_note')->nullable()->after('review_status');
                $table->timestamp('reviewed_at')->nullable()->after('review_note');
                $table->index('review_status');
            });
        }

        if (! Schema::hasColumn('medicine_categories', 'review_status')) {
            Schema::table('medicine_categories', function (Blueprint $table) {
                $table->foreignId('created_by_tenant_id')->nullable()->after('description')->constrained('tenants')->nullOnDelete();
                $table->string('review_status', 10)->default('approved')->after('created_by_tenant_id');
                $table->string('review_note')->nullable()->after('review_status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('medicine_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_tenant_id');
            $table->dropColumn(['review_status', 'review_note']);
        });
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropIndex(['review_status']);
            $table->dropConstrainedForeignId('created_by_tenant_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['review_status', 'review_note', 'reviewed_at']);
        });
    }
};
