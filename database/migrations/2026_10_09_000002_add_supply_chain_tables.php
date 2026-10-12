<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores (main store, dispensaries, wards), suppliers, purchase orders, transfers and supplier returns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guards make the migration re-runnable after a partial failure (MySQL DDL is not transactional)
        if (! Schema::hasTable('stores')) {
            Schema::create('stores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 20);
                $table->string('type', 20)->default('dispensary'); // main, dispensary, opd, ward, emergency, other
                $table->boolean('is_dispensing')->default(true);
                $table->boolean('is_default')->default(false);
                $table->timestamps();

                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name');
                $table->string('type', 20)->default('wholesaler'); // epss, manufacturer, importer, wholesaler, other
                $table->string('tin_number', 20)->nullable();
                $table->string('license_number')->nullable();
                $table->string('phone', 20)->nullable();
                $table->string('email')->nullable();
                $table->string('contact_person')->nullable();
                $table->string('address')->nullable();
                $table->unsignedSmallInteger('payment_terms_days')->default(30);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('medicines', 'tax_category')) {
            Schema::table('medicines', function (Blueprint $table) {
                // exempt (most pharmaceuticals) or standard (VAT/TOT applies, e.g. supplements, cosmetics)
                $table->string('tax_category', 20)->default('exempt')->after('is_controlled');
            });
        }

        if (! Schema::hasColumn('medicine_batches', 'store_id')) {
            Schema::table('medicine_batches', function (Blueprint $table) {
                $table->foreignId('store_id')->nullable()->after('pharmacy_medicine_id')->constrained('stores')->nullOnDelete();
                $table->foreignId('supplier_id')->nullable()->after('supplier')->constrained('suppliers')->nullOnDelete();
                $table->string('gtin', 20)->nullable()->after('batch_number');
            });
        }

        Schema::table('medicine_batches', function (Blueprint $table) {
            if (Schema::hasIndex('medicine_batches', ['pharmacy_medicine_id', 'batch_number'], 'unique')) {
                // Keep an index for the pharmacy_medicine_id foreign key before dropping the old unique
                if (! Schema::hasIndex('medicine_batches', 'batches_listing_idx')) {
                    $table->index('pharmacy_medicine_id', 'batches_listing_idx');
                }
                $table->dropUnique(['pharmacy_medicine_id', 'batch_number']);
            }
            // The same batch can sit in several stores after a transfer (short name: MySQL's 64-char limit)
            $table->unique(['pharmacy_medicine_id', 'store_id', 'batch_number'], 'batches_listing_store_batch_unique');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // in, out, adjustment, expired, return, transfer_in, transfer_out
            $table->string('type', 20)->change();
            $table->foreignId('store_id')->nullable()->after('batch_id')->constrained('stores')->nullOnDelete();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('po_number', 30);
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft, sent, partially_received, received, cancelled
            $table->date('expected_at')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'po_number']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('pharmacy_medicine_id')->constrained('pharmacy_medicines');
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->decimal('unit_cost', 12, 2);
            $table->timestamps();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('transfer_number', 30);
            $table->foreignId('from_store_id')->constrained('stores');
            $table->foreignId('to_store_id')->constrained('stores');
            $table->string('status', 20)->default('requested'); // requested, dispatched, received, cancelled
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'transfer_number']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('pharmacy_medicine_id')->constrained('pharmacy_medicines');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('dispatched_quantity')->default(0);
            $table->json('allocations')->nullable(); // [{batch_id, batch_number, expiry_date, quantity, purchase_price}]
            $table->timestamps();
        });

        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('return_number', 30);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('batch_id')->constrained('medicine_batches');
            $table->unsignedInteger('quantity');
            $table->string('reason', 30); // near_expiry, expired, damaged, recall, wrong_item, other
            $table->decimal('value', 12, 2);
            $table->string('status', 20)->default('pending'); // pending, credited, rejected
            $table->string('credit_note_number')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_returns');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('store_id'));
        Schema::table('medicine_batches', function (Blueprint $t) {
            $t->dropUnique('batches_listing_store_batch_unique');
            $t->dropConstrainedForeignId('store_id');
            $t->dropConstrainedForeignId('supplier_id');
            $t->dropColumn('gtin');
        });
        Schema::table('medicines', fn (Blueprint $t) => $t->dropColumn('tax_category'));
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('stores');
    }
};
