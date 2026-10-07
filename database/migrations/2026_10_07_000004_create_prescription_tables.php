<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete(); // registering hospital
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // linked customer account
            $table->string('mrn')->nullable(); // medical record (card) number
            $table->string('full_name');
            $table->string('phone', 20);
            $table->enum('gender', ['male', 'female']);
            $table->date('date_of_birth')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->string('allergies')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'mrn']);
            $table->index('phone');
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete(); // issuing hospital
            $table->foreignId('doctor_id')->constrained('users');
            $table->foreignId('patient_id')->constrained('patients');
            $table->string('reference_code', 20)->unique();
            $table->text('diagnosis')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'partially_dispensed', 'dispensed', 'expired', 'cancelled'])->default('active');
            $table->dateTime('issued_at');
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines');
            $table->string('dosage');     // e.g. "1 tablet"
            $table->string('frequency');  // e.g. "3 times daily"
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedInteger('total_quantity');
            $table->unsignedInteger('dispensed_quantity')->default(0);
            $table->string('instructions')->nullable();
            $table->timestamps();

            $table->unique(['prescription_id', 'medicine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('patients');
    }
};
