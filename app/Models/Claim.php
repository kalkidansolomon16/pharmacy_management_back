<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monthly statement of everything a payer owes, sent for reimbursement.
 */
class Claim extends Model
{
    use BelongsToTenant, LogsActivity;

    protected $fillable = [
        'tenant_id', 'payer_id', 'claim_number', 'period_start', 'period_end', 'status', 'invoices_count',
        'total_amount', 'paid_amount', 'rejected_amount', 'notes', 'created_by', 'submitted_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'total_amount' => 'decimal:2', 'paid_amount' => 'decimal:2',
            'rejected_amount' => 'decimal:2', 'submitted_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Payer::class)->withoutGlobalScopes();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->withoutGlobalScopes();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->withoutGlobalScopes();
    }

    public function outstanding(): float
    {
        return round((float) $this->total_amount - (float) $this->paid_amount - (float) $this->rejected_amount, 2);
    }

    public function activityLabel(): string
    {
        return $this->claim_number;
    }
}
