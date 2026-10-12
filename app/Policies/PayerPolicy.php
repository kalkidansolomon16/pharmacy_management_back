<?php

namespace App\Policies;

use App\Models\Payer;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermissions;

class PayerPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->any($user, 'insurance.view');
    }

    public function view(User $user, Payer $model): bool
    {
        return $this->own($user, $model, 'insurance.view');
    }

    public function create(User $user): bool
    {
        return $this->any($user, 'insurance.create');
    }

    public function update(User $user, Payer $model): bool
    {
        return $this->own($user, $model, 'insurance.update');
    }
}
