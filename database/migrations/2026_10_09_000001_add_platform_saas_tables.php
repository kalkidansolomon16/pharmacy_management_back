<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules, branches, tax regime and SaaS subscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // A branch belongs to a head-office tenant; owners see all branches together
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
            // Enabled modules: pharmacy, clinical, lab, billing, insurance
            $table->json('modules')->nullable()->after('type');
            // vat = VAT-registered (15%), tot = turnover tax, exempt
            $table->string('tax_regime', 10)->default('exempt')->after('tin_number');
            $table->string('vat_number', 20)->nullable()->after('tax_regime');
            $table->json('settings')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->default('en')->change(); // en, am, om
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('price_monthly', 12, 2);
            $table->json('modules');
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('max_stores')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('status', 20)->default('trial'); // trial, active, past_due, cancelled
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('current_period_start')->nullable();
            $table->dateTime('current_period_end')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 30);
            $table->string('reference')->nullable();
            $table->unsignedSmallInteger('months')->default(1);
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['modules', 'tax_regime', 'vat_number', 'settings']);
        });
    }
};
