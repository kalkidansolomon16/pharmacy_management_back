<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescription_items');
            $table->foreignId('medicine_id')->constrained('medicines');
            $table->text('instruction_note');
            $table->string('frequency');
            $table->string('dosage');
            $table->integer('duration_days');
            $table->integer('despenced_quantity');
            $table->boolean('is_refillable');
            $table->integer('refills_count')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
    }
};
