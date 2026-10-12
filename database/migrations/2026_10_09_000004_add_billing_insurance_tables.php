<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal invoices (Ministry of Revenue e-invoicing), payments, insurance/credit payers, claims and approvals.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Gapless per-tenant numbering for fiscal documents
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);
            $table->unique(['tenant_id', 'type', 'year']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('category', 20); // registration, consultation, procedure, bed, other
            $table->decimal('price', 12, 2);
            $table->string('tax_category', 20)->default('exempt');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('payers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->string('type', 20); // cbhi, private_insurance, corporate, government, ngo
            $table->string('tin_number', 20)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('address')->nullable();
            $table->decimal('default_copay_percent', 5, 2)->default(0);
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->decimal('preauth_threshold', 12, 2)->nullable(); // invoices above need approval
            $table->json('coverage')->nullable();                    // {consultation, lab, medicine, procedure}: bool
            $table->json('excluded_category_ids')->nullable();       // medicine categories not covered
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('payer_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('payer_id')->constrained('payers')->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->string('member_number', 40);
            $table->string('beneficiary_name');
            $table->string('phone', 20)->nullable();
            $table->string('scheme')->nullable();   // e.g. CBHI woreda scheme, insurance plan
            $table->string('employer')->nullable(); // corporate credit
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->decimal('copay_percent', 5, 2)->nullable(); // overrides payer default
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['payer_id', 'member_number']);
        });

        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('payer_id')->constrained('payers');
            $table->string('claim_number', 30);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft'); // draft, submitted, partially_paid, paid, rejected
            $table->unsignedInteger('invoices_count')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('rejected_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'claim_number']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('invoice_number', 30);
            $table->string('document_type', 20)->default('invoice'); // invoice, credit_note
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('customer_tin', 20)->nullable();
            $table->foreignId('payer_id')->nullable()->constrained('payers')->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('payer_memberships')->nullOnDelete();
            $table->foreignId('claim_id')->nullable()->constrained('claims')->nullOnDelete();
            // draft, awaiting_approval, issued, partially_paid, paid, void
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('payer_share', 14, 2)->default(0);
            $table->decimal('patient_share', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0); // by the patient
            $table->decimal('payer_paid', 14, 2)->default(0);
            $table->timestamp('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Ministry of Revenue Electronic Invoice Registration System
            $table->string('irn', 64)->nullable();
            $table->string('rrn', 40)->nullable();
            $table->string('fiscal_status', 20)->default('not_required'); // not_required, pending, accepted, failed
            $table->text('fiscal_qr')->nullable();
            $table->json('fiscal_payload')->nullable();
            $table->json('fiscal_response')->nullable();
            $table->unsignedSmallInteger('fiscal_attempts')->default(0);
            $table->string('fiscal_error')->nullable();
            $table->timestamp('fiscal_registered_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'invoice_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['payer_id', 'claim_id']);
            $table->index('fiscal_status');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('item_type', 20); // registration, consultation, lab, medicine, procedure, other
            $table->nullableMorphs('reference');
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->string('tax_category', 20)->default('exempt');
            $table->decimal('tax_rate', 6, 4)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2); // including tax
            $table->boolean('covered')->default(false);
            $table->decimal('payer_amount', 14, 2)->default(0);
            $table->decimal('patient_amount', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('claim_id')->nullable()->constrained('claims')->nullOnDelete();
            $table->foreignId('payer_id')->nullable()->constrained('payers')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method', 30);
            $table->string('reference')->nullable(); // telebirr / bank transaction id
            $table->string('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['tenant_id', 'paid_at']);
        });

        Schema::create('authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('payer_id')->constrained('payers');
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->string('reference_number')->nullable();   // the payer's approval code
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('tax_total', 12, 2)->default(0)->after('discount');
            $table->foreignId('store_id')->nullable()->after('tenant_id')->constrained('stores')->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->after('prescription_id')->constrained('payer_memberships')->nullOnDelete();
            // Offline POS: the device's id for the sale, so a re-sent sale is never recorded twice
            $table->string('client_uuid', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
            $table->dropConstrainedForeignId('membership_id');
            $table->dropColumn(['tax_total', 'client_uuid']);
        });
        Schema::dropIfExists('authorizations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('claims');
        Schema::dropIfExists('payer_memberships');
        Schema::dropIfExists('payers');
        Schema::dropIfExists('services');
        Schema::dropIfExists('document_sequences');
    }
};
