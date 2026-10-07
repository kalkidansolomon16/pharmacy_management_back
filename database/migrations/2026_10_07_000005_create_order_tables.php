<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 24)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete(); // pharmacy
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // online customer
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // staff for walk-in sales
            $table->foreignId('prescription_id')->nullable()->constrained('prescriptions')->nullOnDelete();
            $table->enum('channel', ['online', 'walk_in'])->default('online');
            $table->enum('status', ['pending', 'confirmed', 'ready', 'completed', 'partially_completed', 'cancelled', 'rejected'])->default('pending');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->enum('fulfillment', ['pickup', 'delivery'])->default('pickup');
            $table->string('delivery_address')->nullable();
            $table->enum('payment_method', ['cash', 'telebirr', 'cbe_birr', 'chapa', 'bank_transfer', 'insurance'])->default('cash');
            $table->enum('payment_status', ['unpaid', 'paid', 'refunded'])->default('unpaid');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('pharmacy_medicine_id')->constrained('pharmacy_medicines');
            $table->foreignId('medicine_id')->constrained('medicines');
            $table->foreignId('prescription_item_id')->nullable()->constrained('prescription_items')->nullOnDelete();
            $table->unsignedInteger('quantity'); // requested
            $table->unsignedInteger('fulfilled_quantity')->default(0);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });

        // Which batches (picked FEFO) supplied each order line, so returns restock the exact batch
        Schema::create('order_item_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('medicine_batches')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_batches');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
