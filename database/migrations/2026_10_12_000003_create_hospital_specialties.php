<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospital specialties (Orthopedics & Spine, Cardiology, Pediatrics...). The list is managed by the platform
 * team, each hospital / clinic picks the ones it offers, and patients filter and book by specialty.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hospital_specialties')) {
            Schema::create('hospital_specialties', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('name_am', 100)->nullable();
                $table->string('slug', 110)->unique();
                $table->string('icon', 40)->nullable();
                $table->string('description', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(100);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('hospital_specialty_tenant')) {
            Schema::create('hospital_specialty_tenant', function (Blueprint $table) {
                $table->foreignId('hospital_specialty_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->primary(['hospital_specialty_id', 'tenant_id']);
            });
        }

        if (Schema::hasTable('appointments') && ! Schema::hasColumn('appointments', 'specialty_id')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->foreignId('specialty_id')->nullable()->after('type')->constrained('hospital_specialties')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('appointments', 'specialty_id')) {
            Schema::table('appointments', fn (Blueprint $table) => $table->dropConstrainedForeignId('specialty_id'));
        }
        Schema::dropIfExists('hospital_specialty_tenant');
        Schema::dropIfExists('hospital_specialties');
    }
};
