<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A role. Built-in roles (pharmacy_admin, staff, doctor...) have no tenant; their default permissions are set
 * by the platform team. Custom roles belong to one organization, which designs them for its own staff.
 */
class Role extends SpatieRole
{
    public const LABELS = [
        User::ROLE_SUPER_ADMIN => 'Platform admin',
        User::ROLE_PHARMACY_ADMIN => 'Pharmacy admin',
        User::ROLE_STAFF => 'Pharmacist',
        User::ROLE_HOSPITAL_ADMIN => 'Hospital admin',
        User::ROLE_DOCTOR => 'Doctor',
        User::ROLE_CUSTOMER => 'Customer',
        User::ROLE_NURSE => 'Nurse',
        User::ROLE_RECEPTIONIST => 'Receptionist',
        User::ROLE_LAB_TECHNICIAN => 'Lab technician',
        User::ROLE_CASHIER => 'Cashier',
        User::ROLE_OWNER => 'Owner',
    ];

    protected $fillable = ['name', 'guard_name', 'tenant_id', 'label', 'description'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * People with this role. Spatie resolves the user model from the *current* auth guard, which is
     * "sanctum" during API requests (no provider) - so name the model explicitly.
     */
    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->morphedByMany(
            User::class,
            'model',
            config('permission.table_names.model_has_roles'),
            app(\Spatie\Permission\PermissionRegistrar::class)->pivotRole,
            config('permission.column_names.model_morph_key')
        );
    }

    public function isCustom(): bool
    {
        return $this->tenant_id !== null;
    }

    public function displayName(): string
    {
        return $this->label ?: (self::LABELS[$this->name] ?? ucwords(str_replace('_', ' ', $this->name)));
    }
}
