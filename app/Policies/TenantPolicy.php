<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tenants.manage');
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->can('tenants.manage') || $user->belongsToTenant($tenant->id);
    }

    /** Platform-level changes: status, verification. */
    public function moderate(User $user, Tenant $tenant): bool
    {
        return $user->can('tenants.manage');
    }

    /** Organization profile / settings. */
    public function update(User $user, Tenant $tenant): bool
    {
        return $user->can('tenants.manage')
            || ($user->can('organization.manage') && $user->belongsToTenant($tenant->id));
    }
}
