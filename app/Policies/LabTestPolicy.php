<?php

namespace App\Policies;

use App\Models\LabTest;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermissions;

class LabTestPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->any($user, 'lab.view', 'lab.order', 'visits.view');
    }

    public function view(User $user, LabTest $model): bool
    {
        return $this->own($user, $model, 'lab.view', 'lab.order', 'visits.view');
    }

    public function create(User $user): bool
    {
        return $this->any($user, 'lab.manage');
    }

    public function update(User $user, LabTest $model): bool
    {
        return $this->own($user, $model, 'lab.manage');
    }
}
