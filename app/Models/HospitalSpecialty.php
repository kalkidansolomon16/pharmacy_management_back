<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A medical specialty a hospital / clinic offers (e.g. Orthopedics & Spine). Managed by the platform team.
 */
class HospitalSpecialty extends Model
{
    protected $fillable = ['name', 'name_am', 'slug', 'icon', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $specialty) {
            if (blank($specialty->slug)) {
                $base = Str::slug($specialty->name);
                $slug = $base;
                for ($i = 2; static::where('slug', $slug)->whereKeyNot($specialty->id)->exists(); $i++) {
                    $slug = "{$base}-{$i}";
                }
                $specialty->slug = $slug;
            }
        });
    }

    public function hospitals(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'hospital_specialty_tenant', 'hospital_specialty_id', 'tenant_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** The starting list (the platform team can rename, add, reorder or switch these off). */
    public const DEFAULTS = [
        ['General Medicine', 'ጠቅላላ ሕክምና', 'stethoscope'],
        ['Internal Medicine', 'የውስጥ ደዌ', 'heart-pulse'],
        ['Orthopedics & Spine', 'የአጥንትና የአከርካሪ', 'bone'],
        ['Cardiology', 'የልብ ሕክምና', 'heart'],
        ['Pediatrics', 'የሕፃናት ሕክምና', 'baby'],
        ['Gynecology & Obstetrics', 'የማህፀንና ጽንስ', 'pregnant'],
        ['General Surgery', 'ቀዶ ሕክምና', 'scalpel'],
        ['Neurology', 'የነርቭ ሕክምና', 'brain'],
        ['Ophthalmology (Eye)', 'የዓይን ሕክምና', 'eye'],
        ['ENT (Ear, Nose & Throat)', 'የጆሮ፣ አፍንጫና ጉሮሮ', 'ear'],
        ['Dental', 'የጥርስ ሕክምና', 'tooth'],
        ['Dermatology (Skin)', 'የቆዳ ሕክምና', 'skin'],
        ['Psychiatry & Mental Health', 'የአእምሮ ጤና', 'mind'],
        ['Urology', 'የሽንት ቧንቧ', 'kidney'],
        ['Oncology (Cancer)', 'የካንሰር ሕክምና', 'ribbon'],
        ['Physiotherapy', 'ፊዚዮቴራፒ', 'physio'],
        ['Radiology & Imaging', 'ራዲዮሎጂ', 'xray'],
        ['Laboratory', 'ላቦራቶሪ', 'lab'],
        ['Emergency', 'ድንገተኛ ሕክምና', 'ambulance'],
    ];
}
