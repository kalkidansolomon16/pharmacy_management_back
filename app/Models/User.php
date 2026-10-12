<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_PHARMACY_ADMIN = 'pharmacy_admin';

    public const ROLE_STAFF = 'staff';

    public const ROLE_HOSPITAL_ADMIN = 'hospital_admin';

    public const ROLE_DOCTOR = 'doctor';

    public const ROLE_CUSTOMER = 'customer';

    public const ROLE_NURSE = 'nurse';

    public const ROLE_RECEPTIONIST = 'receptionist';

    public const ROLE_LAB_TECHNICIAN = 'lab_technician';

    public const ROLE_CASHIER = 'cashier';

    public const ROLE_OWNER = 'owner';

    /** Roles a tenant admin may grant, per tenant type. */
    public const TENANT_ROLES = [
        Tenant::TYPE_PHARMACY => [self::ROLE_PHARMACY_ADMIN, self::ROLE_STAFF],
        Tenant::TYPE_HOSPITAL => [self::ROLE_HOSPITAL_ADMIN, self::ROLE_DOCTOR],
    ];

    /** Extra roles that become available when an organization uses a module. */
    public const MODULE_ROLES = [
        'clinical' => [self::ROLE_DOCTOR, self::ROLE_NURSE, self::ROLE_RECEPTIONIST],
        'lab' => [self::ROLE_LAB_TECHNICIAN],
        'pharmacy' => [self::ROLE_STAFF],
        'billing' => [self::ROLE_CASHIER],
    ];

    /** A clinician who writes prescriptions (the Doctor role or any custom role with that permission). */
    public function isPrescriber(): bool
    {
        return $this->can('prescriptions.create');
    }

    /** Every role an admin of this organization may grant: type roles + module roles + owner. */
    public static function assignableRolesFor(Tenant $tenant): array
    {
        $roles = self::TENANT_ROLES[$tenant->type] ?? [];
        foreach ($tenant->enabledModules() as $module) {
            $roles = array_merge($roles, self::MODULE_ROLES[$module] ?? []);
        }

        $custom = Role::where('tenant_id', $tenant->id)->pluck('name')->all();

        return array_values(array_unique([...$roles, self::ROLE_OWNER, ...$custom]));
    }

    protected string $guard_name = 'web';

    protected $fillable = [
        'tenant_id', 'name', 'email', 'phone', 'password', 'status',
        'city', 'address', 'license_number', 'locale', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN);
    }

    public function belongsToTenant(?int $tenantId): bool
    {
        return $tenantId !== null && $this->tenant_id === $tenantId;
    }

    public function primaryRole(): ?string
    {
        return $this->getRoleNames()->first();
    }
}
