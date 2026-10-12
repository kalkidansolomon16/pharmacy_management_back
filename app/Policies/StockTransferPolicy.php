<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermissions;

class StockTransferPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->any($user, 'inventory.view');
    }

    public function view(User $user, StockTransfer $model): bool
    {
        return $this->own($user, $model, 'inventory.view');
    }

    public function create(User $user): bool
    {
        return $this->any($user, 'transfers.manage');
    }

    public function update(User $user, StockTransfer $model): bool
    {
        return $this->own($user, $model, 'transfers.manage');
    }
}
