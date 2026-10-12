<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medicine extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'category_id', 'generic_name', 'brand_name', 'manufacturer', 'dosage_form', 'strength', 'unit',
        'barcode', 'prescription_required', 'is_controlled', 'storage_conditions', 'description', 'is_active',
        'tax_category', 'created_by_tenant_id', 'created_by', 'review_status', 'review_note', 'reviewed_at',
    ];

    public const REVIEW_STATUSES = ['approved', 'pending', 'rejected'];

    protected function casts(): array
    {
        return [
            'prescription_required' => 'boolean',
            'is_controlled' => 'boolean',
            'is_active' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function contributor(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'created_by_tenant_id');
    }

    /**
     * What a user may see and use: the approved shared catalogue plus their own organization's
     * submissions (pending or rejected). The platform team sees everything.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->isSuperAdmin()) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where('medicines.review_status', 'approved')
            ->when($user?->tenant_id, fn ($w, $tenantId) => $w->orWhere('medicines.created_by_tenant_id', $tenantId)));
    }

    public function isApproved(): bool
    {
        return $this->review_status === 'approved';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MedicineCategory::class, 'category_id');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(PharmacyMedicine::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('generic_name', 'like', "%{$term}%")
                ->orWhere('brand_name', 'like', "%{$term}%")
                ->orWhere('manufacturer', 'like', "%{$term}%")
                ->orWhere('barcode', $term);
        });
    }

    public function displayName(): string
    {
        return trim($this->generic_name.' '.$this->strength.($this->brand_name ? " ({$this->brand_name})" : ''));
    }

    public function activityLabel(): string
    {
        return $this->displayName();
    }
}
