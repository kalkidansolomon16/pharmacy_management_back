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
    ];

    protected function casts(): array
    {
        return [
            'prescription_required' => 'boolean',
            'is_controlled' => 'boolean',
            'is_active' => 'boolean',
        ];
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
