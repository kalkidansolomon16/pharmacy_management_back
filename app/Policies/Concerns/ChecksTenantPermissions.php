<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared checks: the user holds one of the permissions, and (for a record) it belongs to their organization.
 */
trait ChecksTenantPermissions
{
    protected function any(User $user, string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    protected function own(User $user, Model $model, string ...$permissions): bool
    {
        return $user->belongsToTenant($model->getAttribute('tenant_id')) && $this->any($user, ...$permissions);
    }
}
