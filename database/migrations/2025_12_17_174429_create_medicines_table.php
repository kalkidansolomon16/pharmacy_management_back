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
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_category_id')->constrained('medicine_categories');
            $table->string('generic_name');
            $table->string('brand_name');
            $table->string('bar_code')->nullable();
            $table->string('dosage_form');
            $table->string('strength');
            $table->integer('pack_size');
            $table->boolean('is_narcotic');
            $table->string('storage_conditions');
            $table->string('indications');
            $table->string('image');
            $table->enum('status',['active','inactive']);
            $table->boolean('prescription_required');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medicines');
    }
};
