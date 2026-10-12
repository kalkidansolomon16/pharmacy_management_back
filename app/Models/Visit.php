<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An encounter: one trip of a patient through the facility, from check-in to completion.
 */
class Visit extends Model
{
    use BelongsToTenant, LogsActivity;

    public const STATUSES = ['waiting', 'in_consultation', 'awaiting_lab', 'awaiting_review', 'completed', 'cancelled'];

    public const OPEN_STATUSES = ['waiting', 'in_consultation', 'awaiting_lab', 'awaiting_review'];

    public const TYPES = ['opd', 'emergency', 'follow_up', 'antenatal', 'other'];

    protected $fillable = [
        'tenant_id', 'patient_id', 'visit_number', 'queue_number', 'type', 'department', 'status', 'priority',
        'doctor_id', 'membership_id', 'chief_complaint', 'vitals', 'history', 'examination', 'diagnoses', 'plan',
        'checked_in_by', 'checked_in_at', 'triaged_at', 'seen_at', 'completed_at',
    ];

    /** Clinical narrative is encrypted at rest. */
    protected function casts(): array
    {
        return [
            'vitals' => 'array',
            'diagnoses' => 'array',
            'history' => 'encrypted',
            'examination' => 'encrypted',
            'plan' => 'encrypted',
            'checked_in_at' => 'datetime',
            'triaged_at' => 'datetime',
            'seen_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** Keep encrypted clinical notes out of the audit trail. */
    protected $hidden = ['history', 'examination', 'plan'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withoutGlobalScopes();
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(PayerMembership::class, 'membership_id')->withoutGlobalScopes();
    }

    public function labOrders(): HasMany
    {
        return $this->hasMany(LabOrder::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function activityLabel(): string
    {
        return $this->visit_number;
    }
}
