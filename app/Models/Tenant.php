<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory, LogsActivity;

    public const TYPE_PHARMACY = 'pharmacy';

    public const TYPE_HOSPITAL = 'hospital';

    protected $fillable = [
        'name', 'slug', 'type', 'status', 'license_number', 'tin_number', 'phone', 'email',
        'region', 'city', 'sub_city', 'woreda', 'address_line', 'latitude', 'longitude',
        'opening_hours', 'is_24_hours', 'delivery_available', 'description', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'is_24_hours' => 'boolean',
            'delivery_available' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->slug)) {
                $base = Str::slug($tenant->name) ?: 'tenant';
                $slug = $base;
                $i = 2;
                while (static::where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $tenant->slug = $slug;
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function pharmacyMedicines(): HasMany
    {
        return $this->hasMany(PharmacyMedicine::class);
    }

    public function isPharmacy(): bool
    {
        return $this->type === self::TYPE_PHARMACY;
    }

    public function isHospital(): bool
    {
        return $this->type === self::TYPE_HOSPITAL;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopePharmacies(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PHARMACY);
    }

    public function activityLabel(): string
    {
        return $this->name;
    }
}
