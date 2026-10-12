<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's coverage with a payer (CBHI member card, insurance policy, employee credit).
 */
class PayerMembership extends Model
{
    use BelongsToTenant, LogsActivity;

    protected $fillable = [
        'tenant_id', 'payer_id', 'patient_id', 'member_number', 'beneficiary_name', 'phone', 'scheme',
        'employer', 'valid_from', 'valid_to', 'copay_percent', 'status',
    ];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'copay_percent' => 'float'];
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Payer::class)->withoutGlobalScopes();
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withoutGlobalScopes();
    }

    public function isValid(): bool
    {
        return $this->status === 'active'
            && (! $this->valid_from || $this->valid_from->lte(today()))
            && (! $this->valid_to || $this->valid_to->gte(today()))
            && ($this->payer?->is_active ?? false);
    }

    /** Patient's share in percent: membership override, else the payer default. */
    public function copayPercent(): float
    {
        return $this->copay_percent ?? $this->payer->default_copay_percent ?? 0;
    }

    public function activityLabel(): string
    {
        return "{$this->beneficiary_name} ({$this->member_number})";
    }
}
