<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', ['pharmacy', 'hospital']);
            $table->enum('status', ['pending', 'active', 'suspended', 'rejected'])->default('pending');
            // Ethiopian Food and Drug Authority (EFDA) / MoH licence and tax registration
            $table->string('license_number')->nullable();
            $table->string('tin_number', 20)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();
            $table->string('sub_city')->nullable();
            $table->string('woreda')->nullable();
            $table->string('address_line')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('opening_hours')->nullable();
            $table->boolean('is_24_hours')->default(false);
            $table->boolean('delivery_available')->default(false);
            $table->text('description')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
