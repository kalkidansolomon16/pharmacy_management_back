<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicineBatch extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'pharmacy_medicine_id', 'batch_number', 'initial_quantity', 'quantity',
        'expiry_date', 'purchase_price', 'supplier', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'received_at' => 'date',
            'purchase_price' => 'decimal:2',
            'quantity' => 'integer',
            'initial_quantity' => 'integer',
        ];
    }

    public function pharmacyMedicine(): BelongsTo
    {
        return $this->belongsTo(PharmacyMedicine::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'batch_id');
    }

    /** A batch expiring today is no longer sellable. */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0)->whereDate('expiry_date', '>', today());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereDate('expiry_date', '<=', today());
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->sellable()->whereDate('expiry_date', '<=', today()->addDays($days));
    }

    /** First-Expiry-First-Out ordering. */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderBy('expiry_date')->orderBy('id');
    }

    public function isExpired(): bool
    {
        return $this->expiry_date->lte(today());
    }
}
