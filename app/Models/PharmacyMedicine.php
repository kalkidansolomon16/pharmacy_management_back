<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PharmacyMedicine extends Model
{
    use BelongsToTenant, HasFactory, LogsActivity;

    protected $fillable = ['tenant_id', 'medicine_id', 'price', 'reorder_level', 'is_public'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_public' => 'boolean',
            'reorder_level' => 'integer',
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(MedicineBatch::class);
    }

    /** Batches that can legally be sold: in stock and not past expiry. */
    public function sellableBatches(): HasMany
    {
        return $this->batches()->sellable();
    }

    /**
     * Adds `available_quantity` (non-expired stock) to each row.
     */
    public function scopeWithAvailableQuantity(Builder $query): Builder
    {
        return $query->withSum(['batches as available_quantity' => fn ($q) => $q->sellable()], 'quantity');
    }

    public function scopeWithNearestExpiry(Builder $query): Builder
    {
        return $query->withMin(['batches as nearest_expiry' => fn ($q) => $q->sellable()], 'expiry_date');
    }

    /**
     * Compare sellable stock against a value or column, e.g. whereStock('<=', 'reorder_level').
     * A correlated sub-query keeps this portable (no HAVING on aliases).
     */
    public function scopeWhereStock(Builder $query, string $operator, string|int $against): Builder
    {
        $operator = in_array($operator, ['=', '<', '<=', '>', '>='], true) ? $operator : '=';
        $right = is_int($against) ? (string) $against : $query->qualifyColumn($against);

        return $query->whereRaw(
            "(select coalesce(sum(b.quantity), 0) from medicine_batches b
              where b.pharmacy_medicine_id = pharmacy_medicines.id and b.quantity > 0 and date(b.expiry_date) > ?) {$operator} {$right}",
            [today()->toDateString()]
        );
    }

    public function availableQuantity(): int
    {
        return (int) $this->sellableBatches()->sum('quantity');
    }

    public function activityLabel(): string
    {
        return $this->medicine?->displayName() ?? parent::activityLabel();
    }
}
