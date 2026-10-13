<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organization asks to move to another plan; the platform team approves or rejects.
 * Upgrades apply on approval, downgrades at the end of the paid period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); // the organization that pays (head office)
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('direction', 12); // upgrade | downgrade
            $table->string('status', 12)->default('pending'); // pending | scheduled | applied | rejected | cancelled
            $table->text('note')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_change_requests');
    }
};
