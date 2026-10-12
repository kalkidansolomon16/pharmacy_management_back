<?php

namespace App\Policies;

use App\Models\SupplierReturn;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermissions;

class SupplierReturnPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->any($user, 'inventory.view');
    }

    public function view(User $user, SupplierReturn $model): bool
    {
        return $this->own($user, $model, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->any($user, 'returns.manage');
    }

    public function update(User $user, SupplierReturn $model): bool
    {
        return $this->own($user, $model, 'returns.manage');
    }
}
