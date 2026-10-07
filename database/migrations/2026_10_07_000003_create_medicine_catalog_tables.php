<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_am')->nullable();
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Platform-wide master catalogue, maintained by the super admin
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('medicine_categories')->nullOnDelete();
            $table->string('generic_name');
            $table->string('brand_name')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('dosage_form');
            $table->string('strength');
            $table->string('unit')->default('tablet'); // dispensing unit
            $table->string('barcode')->nullable()->unique();
            $table->boolean('prescription_required')->default(false);
            $table->boolean('is_controlled')->default(false); // narcotic / psychotropic
            $table->string('storage_conditions')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('generic_name');
            $table->index('brand_name');
        });

        // A pharmacy's own listing of a catalogue medicine: its price and visibility
        Schema::create('pharmacy_medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->cascadeOnDelete();
            $table->decimal('price', 12, 2); // ETB per dispensing unit
            $table->unsignedInteger('reorder_level')->default(20);
            $table->boolean('is_public')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'medicine_id']);
        });

        Schema::create('medicine_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('pharmacy_medicine_id')->constrained('pharmacy_medicines')->cascadeOnDelete();
            $table->string('batch_number');
            $table->unsignedInteger('initial_quantity');
            $table->unsignedInteger('quantity'); // remaining
            $table->date('expiry_date');
            $table->decimal('purchase_price', 12, 2);
            $table->string('supplier')->nullable(); // e.g. EPSS, private importer
            $table->date('received_at')->nullable();
            $table->timestamps();

            $table->unique(['pharmacy_medicine_id', 'batch_number']);
            $table->index(['pharmacy_medicine_id', 'expiry_date']); // FEFO lookups
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('medicine_batches')->cascadeOnDelete();
            $table->enum('type', ['in', 'out', 'adjustment', 'expired', 'return']);
            $table->integer('quantity'); // signed: positive adds stock, negative removes it
            $table->string('reason')->nullable();
            $table->nullableMorphs('reference'); // e.g. the order that consumed the stock
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('medicine_batches');
        Schema::dropIfExists('pharmacy_medicines');
        Schema::dropIfExists('medicines');
        Schema::dropIfExists('medicine_categories');
    }
};
