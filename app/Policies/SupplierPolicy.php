<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermissions;

class SupplierPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->any($user, 'inventory.view', 'purchasing.view');
    }

    public function view(User $user, Supplier $model): bool
    {
        return $this->own($user, $model, 'inventory.view', 'purchasing.view');
    }

    public function create(User $user): bool
    {
        return $this->any($user, 'purchasing.create');
    }

    public function update(User $user, Supplier $model): bool
    {
        return $this->own($user, $model, 'purchasing.update');
    }
}
