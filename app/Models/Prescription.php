<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prescription extends Model
{
    use BelongsToTenant, HasFactory;

    public const DISPENSABLE_STATUSES = ['active', 'partially_dispensed'];

    protected $fillable = [
        'tenant_id', 'doctor_id', 'patient_id', 'reference_code', 'diagnosis', 'notes',
        'status', 'issued_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /** Patients are owned by the issuing hospital, so cross-tenant readers bypass the scope. */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withoutGlobalScopes();
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isDispensable(): bool
    {
        return in_array($this->status, self::DISPENSABLE_STATUSES, true) && ! $this->isExpired();
    }

    public function scopeDispensable(Builder $query): Builder
    {
        return $query->whereIn('status', self::DISPENSABLE_STATUSES)->where('expires_at', '>', now());
    }
}
