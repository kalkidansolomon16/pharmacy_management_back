<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermissions;

class StorePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->any($user, 'inventory.view');
    }

    public function view(User $user, Store $model): bool
    {
        return $this->own($user, $model, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->any($user, 'stores.create');
    }

    public function update(User $user, Store $model): bool
    {
        return $this->own($user, $model, 'stores.update');
    }
}
