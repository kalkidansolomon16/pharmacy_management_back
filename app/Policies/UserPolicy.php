<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $target): bool
    {
        return $user->id !== $target->id && $this->manages($user, $target, 'users.update');
    }

    public function delete(User $user, User $target): bool
    {
        return $user->id !== $target->id && $this->manages($user, $target, 'users.delete');
    }

    /** Super admins manage everyone except other super admins; tenant admins manage their own staff. */
    private function manages(User $user, User $target, string $permission): bool
    {
        if (! $user->can($permission) || $target->isSuperAdmin()) {
            return false;
        }

        return $user->isSuperAdmin() || $user->belongsToTenant($target->tenant_id);
    }
}
