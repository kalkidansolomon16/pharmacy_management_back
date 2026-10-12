<?php

namespace App\Policies;

use App\Models\PayerAuthorization;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermissions;

class PayerAuthorizationPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->any($user, 'insurance.view');
    }

    public function view(User $user, PayerAuthorization $model): bool
    {
        return $this->own($user, $model, 'insurance.view');
    }

    public function create(User $user): bool
    {
        return $this->any($user, 'insurance.claims');
    }

    public function update(User $user, PayerAuthorization $model): bool
    {
        return $this->own($user, $model, 'insurance.claims');
    }
}
