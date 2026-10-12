<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fiscal invoice / receipt. Never deleted: corrections are voided or credited.
 */
class Invoice extends Model
{
    use BelongsToTenant, LogsActivity;

    public const OPEN_STATUSES = ['awaiting_approval', 'issued', 'partially_paid'];

    protected $fillable = [
        'tenant_id', 'invoice_number', 'document_type', 'patient_id', 'visit_id', 'order_id', 'customer_name',
        'customer_phone', 'customer_tin', 'payer_id', 'membership_id', 'claim_id', 'status', 'subtotal', 'discount',
        'tax_total', 'total', 'payer_share', 'patient_share', 'amount_paid', 'payer_paid', 'payer_rejected', 'issued_at', 'due_at',
        'voided_at', 'void_reason', 'created_by', 'irn', 'rrn', 'fiscal_status', 'fiscal_qr', 'fiscal_payload',
        'fiscal_response', 'fiscal_attempts', 'fiscal_error', 'fiscal_registered_at',
    ];

    protected $hidden = ['fiscal_payload', 'fiscal_response'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2',
            'payer_share' => 'decimal:2', 'patient_share' => 'decimal:2', 'amount_paid' => 'decimal:2',
            'payer_paid' => 'decimal:2', 'payer_rejected' => 'decimal:2', 'issued_at' => 'datetime', 'due_at' => 'date', 'voided_at' => 'datetime',
            'fiscal_payload' => 'array', 'fiscal_response' => 'array', 'fiscal_registered_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->withoutGlobalScopes();
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withoutGlobalScopes();
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class)->withoutGlobalScopes();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Payer::class)->withoutGlobalScopes();
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(PayerMembership::class, 'membership_id')->withoutGlobalScopes();
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class)->withoutGlobalScopes();
    }

    public function authorizations(): HasMany
    {
        return $this->hasMany(PayerAuthorization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function patientBalance(): float
    {
        return round((float) $this->patient_share - (float) $this->amount_paid, 2);
    }

    public function payerBalance(): float
    {
        // What the insurer still owes: its share minus what it paid and what it refused (written off)
        return round((float) $this->payer_share - (float) $this->payer_paid - (float) $this->payer_rejected, 2);
    }

    /** Patient part settled (or nothing to pay) - services may proceed. */
    public function isPatientSettled(): bool
    {
        return $this->status !== 'void' && $this->patientBalance() <= 0.009;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function activityLabel(): string
    {
        return $this->invoice_number;
    }
}
