<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's request to upgrade or downgrade its subscription plan.
 * Not tenant-scoped: the platform team reviews requests from every organization.
 */
class PlanChangeRequest extends Model
{
    public const OPEN_STATUSES = ['pending', 'scheduled'];

    protected $fillable = [
        'tenant_id', 'subscription_id', 'from_plan_id', 'to_plan_id', 'direction', 'status', 'note', 'admin_note',
        'effective_at', 'requested_by', 'decided_by', 'decided_at', 'applied_at',
    ];

    protected function casts(): array
    {
        return ['effective_at' => 'datetime', 'decided_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }
}
