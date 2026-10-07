<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $target): bool
    {
        return $user->id !== $target->id && $this->manages($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->id !== $target->id && $this->manages($user, $target);
    }

    /** Super admins manage everyone except other super admins; tenant admins manage their own staff. */
    private function manages(User $user, User $target): bool
    {
        if (! $user->can('users.manage') || $target->isSuperAdmin()) {
            return false;
        }

        return $user->isSuperAdmin() || $user->belongsToTenant($target->tenant_id);
    }
}
