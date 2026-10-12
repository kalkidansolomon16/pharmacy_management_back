<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use LogsActivity;

    /** Days after the period ends during which everything still works. */
    public const GRACE_DAYS = 7;

    protected $fillable = ['tenant_id', 'plan_id', 'status', 'trial_ends_at', 'current_period_start', 'current_period_end'];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'current_period_start' => 'datetime', 'current_period_end' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function endsAt(): ?\Carbon\CarbonInterface
    {
        return $this->status === 'trial' ? $this->trial_ends_at : $this->current_period_end;
    }

    /** Usable: trialing / paid up, or within the grace period. Cancelled is never usable. */
    public function isUsable(): bool
    {
        if ($this->status === 'cancelled') {
            return false;
        }
        $end = $this->endsAt();

        return ! $end || $end->copy()->addDays(self::GRACE_DAYS)->isFuture();
    }

    public function daysLeft(): ?int
    {
        $end = $this->endsAt();

        return $end ? (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay(), false) : null;
    }

    public function activityLabel(): string
    {
        return 'Subscription '.$this->plan?->name;
    }
}
