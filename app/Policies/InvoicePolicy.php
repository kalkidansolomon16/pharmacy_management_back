<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantPermissions;

class InvoicePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->any($user, 'billing.view');
    }

    public function view(User $user, Invoice $model): bool
    {
        return $this->own($user, $model, 'billing.view');
    }

    public function create(User $user): bool
    {
        return $this->any($user, 'billing.create');
    }

    public function update(User $user, Invoice $model): bool
    {
        return $this->own($user, $model, 'billing.create');
    }

    public function collect(User $user, Invoice $invoice): bool
    {
        return $this->own($user, $invoice, 'billing.collect');
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $this->own($user, $invoice, 'billing.void');
    }
}
