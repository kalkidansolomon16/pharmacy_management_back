<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MedicineCategory extends Model
{
    protected $fillable = ['name', 'name_am', 'slug', 'icon', 'description', 'created_by_tenant_id', 'review_status', 'review_note'];

    /** Approved categories plus the user's own organization's submissions; everything for the platform team. */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, ?User $user): \Illuminate\Database\Eloquent\Builder
    {
        if ($user?->isSuperAdmin()) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where('review_status', 'approved')
            ->when($user?->tenant_id, fn ($w, $tenantId) => $w->orWhere('created_by_tenant_id', $tenantId)));
    }

    public function contributor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'created_by_tenant_id');
    }

    protected static function booted(): void
    {
        static::saving(function (MedicineCategory $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function medicines(): HasMany
    {
        return $this->hasMany(Medicine::class, 'category_id');
    }
}
