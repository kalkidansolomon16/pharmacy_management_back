<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hospital's own (in-house) pharmacy: a pharmacy with its own EFDA licence that belongs to a hospital.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'hospital_id')) {
                $table->foreignId('hospital_id')->nullable()->after('parent_id')->constrained('tenants')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hospital_id');
        });
    }
};
