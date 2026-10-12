<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The connected patient journey: visit (encounter) -> triage -> consultation -> lab -> prescription.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients');
            $table->string('visit_number', 30)->unique();
            $table->unsignedSmallInteger('queue_number');
            $table->string('type', 20)->default('opd'); // opd, emergency, follow_up, antenatal, other
            $table->string('department')->nullable();
            // waiting, in_consultation, awaiting_lab, awaiting_review, completed, cancelled
            $table->string('status', 30)->default('waiting');
            $table->string('priority', 10)->default('normal'); // normal, urgent
            $table->foreignId('doctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('membership_id')->nullable(); // insurance coverage used for this visit
            $table->text('chief_complaint')->nullable();
            $table->json('vitals')->nullable();
            $table->text('history')->nullable();      // encrypted at rest
            $table->text('examination')->nullable();  // encrypted at rest
            $table->json('diagnoses')->nullable();    // [{code, name}] ICD-10
            $table->text('plan')->nullable();         // encrypted at rest
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_in_at');
            $table->timestamp('triaged_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'checked_in_at']);
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->foreignId('visit_id')->nullable()->after('patient_id')->constrained('visits')->nullOnDelete();
            // e-prescription routed to a specific pharmacy (the hospital's own, or an external partner)
            $table->foreignId('pharmacy_id')->nullable()->after('visit_id')->constrained('tenants')->nullOnDelete();
        });

        Schema::create('lab_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('category', 30); // hematology, chemistry, parasitology, serology, urinalysis, microbiology
            $table->string('specimen', 30)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('result_type', 20)->default('numeric'); // numeric, text, positive_negative, select
            $table->json('options')->nullable();
            $table->decimal('reference_low', 12, 3)->nullable();
            $table->decimal('reference_high', 12, 3)->nullable();
            $table->decimal('critical_low', 12, 3)->nullable();
            $table->decimal('critical_high', 12, 3)->nullable();
            $table->string('reference_text')->nullable();
            $table->decimal('price', 12, 2);
            $table->unsignedSmallInteger('turnaround_minutes')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('order_number', 30)->unique();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignId('patient_id')->constrained('patients');
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('ordered'); // ordered, collected, completed, cancelled
            $table->string('priority', 10)->default('routine');
            $table->text('clinical_notes')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('lab_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained('lab_orders')->cascadeOnDelete();
            $table->foreignId('lab_test_id')->constrained('lab_tests');
            $table->decimal('price', 12, 2);
            $table->string('status', 20)->default('pending'); // pending, resulted, verified
            $table->decimal('result_value', 12, 3)->nullable();
            $table->string('result_text')->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('flag', 20)->nullable(); // normal, low, high, critical, positive, negative
            $table->string('reference')->nullable();
            $table->string('remarks')->nullable();
            $table->foreignId('resulted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resulted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_order_items');
        Schema::dropIfExists('lab_orders');
        Schema::dropIfExists('lab_tests');
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visit_id');
            $table->dropConstrainedForeignId('pharmacy_id');
        });
        Schema::dropIfExists('visits');
    }
};
