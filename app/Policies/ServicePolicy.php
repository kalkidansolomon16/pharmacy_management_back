<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermissions;

class ServicePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->any($user, 'billing.view', 'visits.manage', 'services.create', 'services.update');
    }

    public function view(User $user, Service $model): bool
    {
        return $this->own($user, $model, 'billing.view', 'visits.manage', 'services.create', 'services.update');
    }

    public function create(User $user): bool
    {
        return $this->any($user, 'services.create');
    }

    public function update(User $user, Service $model): bool
    {
        return $this->own($user, $model, 'services.update');
    }
}
