<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermissions;

class PurchaseOrderPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->any($user, 'purchasing.view');
    }

    public function view(User $user, PurchaseOrder $model): bool
    {
        return $this->own($user, $model, 'purchasing.view');
    }

    public function create(User $user): bool
    {
        return $this->any($user, 'purchasing.create');
    }

    public function update(User $user, PurchaseOrder $model): bool
    {
        return $this->own($user, $model, 'purchasing.update');
    }
}
