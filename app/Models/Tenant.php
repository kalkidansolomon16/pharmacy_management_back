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
        'parent_id', 'modules', 'tax_regime', 'vat_number', 'settings',
        'status_reason', 'status_changed_at',
    ];

    public const MODULES = ['pharmacy', 'clinical', 'lab', 'billing', 'insurance'];

    /** Modules switched on when an organization has not chosen explicitly. */
    public const DEFAULT_MODULES = [
        self::TYPE_PHARMACY => ['pharmacy', 'billing', 'insurance'],
        self::TYPE_HOSPITAL => ['clinical', 'lab', 'billing', 'insurance'],
    ];

    /** Organization-level switches, with defaults. */
    public const DEFAULT_SETTINGS = [
        'payment_before_service' => false,  // lab & pharmacy wait for the bill to be settled (or insured)
        'registration_fee_on_check_in' => true,
        'einvoice_enabled' => true,          // register invoices with the Ministry of Revenue
        'four_eyes_lab_verification' => false, // lab results verified by a second person
    ];

    protected function casts(): array
    {
        return [
            'is_24_hours' => 'boolean',
            'delivery_available' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'approved_at' => 'datetime',
            'modules' => 'array',
            'settings' => 'array',
        ];
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'parent_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Tenant::class, 'parent_id');
    }

    public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /**
     * Modules this organization can use: its own choice, limited to what its subscription plan includes.
     */
    public function enabledModules(): array
    {
        $modules = $this->modules ?: self::DEFAULT_MODULES[$this->type] ?? [];
        $plan = $this->billingSubscription()?->plan;

        return array_values($plan ? array_intersect($modules, $plan->modules) : $modules);
    }

    /** The subscription this organization runs on: its own, or (for a branch) its head office's. */
    public function billingSubscription(): ?Subscription
    {
        $own = $this->relationLoaded('subscription') ? $this->subscription : $this->subscription()->with('plan')->first();
        if ($own || ! $this->parent_id) {
            return $own;
        }

        return $this->parent?->subscription()->with('plan')->first();
    }

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->enabledModules(), true);
    }

    public function setting(string $key): mixed
    {
        return ($this->settings ?? [])[$key] ?? self::DEFAULT_SETTINGS[$key] ?? null;
    }

    /** This tenant and every branch below it (for owner dashboards). */
    public function familyIds(): array
    {
        return array_merge([$this->id], $this->branches()->pluck('id')->all());
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

    /**
     * Each organization needs its own email address (it receives MedLink notices).
     * Pass the organization being edited so it does not clash with itself.
     */
    public static function uniqueEmailRule(?int $ignoreId = null): \Illuminate\Validation\Rules\Unique
    {
        return \Illuminate\Validation\Rule::unique('tenants', 'email')->ignore($ignoreId);
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
