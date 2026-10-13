<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patients book a time at a hospital / clinic. On the day, reception checks them in and the
 * appointment becomes a normal visit in the patient queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('appointments')) {
            return;
        }
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();     // the hospital / clinic
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // the patient's MedLink account
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete(); // linked at check-in
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 20)->unique();
            $table->dateTime('scheduled_at');
            $table->string('type', 20)->default('opd');
            $table->string('patient_name', 150);
            $table->string('patient_phone', 20);
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('reason', 500);
            $table->string('status', 20)->default('booked'); // booked, checked_in, completed, cancelled, no_show
            $table->string('cancel_reason', 191)->nullable();
            $table->string('cancelled_by', 20)->nullable();   // patient | hospital
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'scheduled_at']);
            $table->index(['user_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
