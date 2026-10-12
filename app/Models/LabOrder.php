<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'order_number', 'visit_id', 'patient_id', 'ordered_by', 'status', 'priority',
        'clinical_notes', 'collected_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['collected_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(LabOrderItem::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withoutGlobalScopes();
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class)->withoutGlobalScopes();
    }

    public function orderer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }
}
