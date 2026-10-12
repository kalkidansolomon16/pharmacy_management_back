<?php

namespace App\Policies;

use App\Models\LabOrder;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermissions;

class LabOrderPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->any($user, 'lab.view');
    }

    public function view(User $user, LabOrder $model): bool
    {
        return $this->own($user, $model, 'lab.view');
    }

    public function create(User $user): bool
    {
        return $this->any($user, 'lab.order');
    }

    public function update(User $user, LabOrder $model): bool
    {
        return $this->own($user, $model, 'lab.order');
    }

    public function process(User $user, LabOrder $order): bool
    {
        return $this->own($user, $order, 'lab.result');
    }

    public function verify(User $user, LabOrder $order): bool
    {
        return $this->own($user, $order, 'lab.verify');
    }
}
