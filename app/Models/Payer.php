<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who pays besides the patient: CBHI scheme, private insurer, employer credit agreement...
 */
class Payer extends Model
{
    use BelongsToTenant, LogsActivity;

    public const TYPES = ['cbhi', 'private_insurance', 'corporate', 'government', 'ngo'];

    public const COVERAGE_KEYS = ['registration', 'consultation', 'lab', 'medicine', 'procedure', 'other'];

    protected $fillable = [
        'tenant_id', 'name', 'code', 'type', 'tin_number', 'phone', 'email', 'contact_person', 'address',
        'default_copay_percent', 'credit_limit', 'payment_terms_days', 'preauth_threshold', 'coverage',
        'excluded_category_ids', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_copay_percent' => 'float',
            'credit_limit' => 'float',
            'preauth_threshold' => 'float',
            'coverage' => 'array',
            'excluded_category_ids' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(PayerMembership::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function covers(string $itemType): bool
    {
        $coverage = $this->coverage ?? array_fill_keys(self::COVERAGE_KEYS, true);

        return (bool) ($coverage[$itemType] ?? false);
    }

    /** Money owed by this payer: its share of issued invoices not yet paid. */
    public function outstanding(): float
    {
        return (float) $this->invoices()->withoutGlobalScopes()
            ->whereNotIn('status', ['draft', 'void'])
            ->selectRaw('coalesce(sum(payer_share - payer_paid - payer_rejected), 0) as due')
            ->value('due');
    }

    public function activityLabel(): string
    {
        return $this->name;
    }
}
