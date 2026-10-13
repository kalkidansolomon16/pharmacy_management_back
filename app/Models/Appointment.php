<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A patient's booked time at a hospital / clinic.
 */
class Appointment extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['booked', 'checked_in', 'completed', 'cancelled', 'no_show'];

    /** Statuses that take up a place in a time slot. */
    public const ACTIVE_STATUSES = ['booked', 'checked_in', 'completed'];

    protected $fillable = [
        'tenant_id', 'user_id', 'patient_id', 'visit_id', 'reference', 'scheduled_at', 'type', 'specialty_id',
        'patient_name', 'patient_phone', 'gender', 'date_of_birth', 'reason',
        'status', 'cancel_reason', 'cancelled_by', 'checked_in_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'date_of_birth' => 'date',
            'checked_in_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(HospitalSpecialty::class, 'specialty_id');
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isUpcoming(): bool
    {
        return $this->status === 'booked' && $this->scheduled_at->isFuture();
    }
}
