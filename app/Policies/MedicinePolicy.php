<?php

namespace App\Policies;

use App\Models\Medicine;
use App\Models\User;

class MedicinePolicy
{
    /** Every signed-in user may browse the master catalogue (to stock or prescribe from it). */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Medicine $medicine): bool
    {
        return $medicine->isApproved() || $user->can('catalog.manage') || ($user->tenant_id && $medicine->created_by_tenant_id === $user->tenant_id);
    }

    /** Platform team, or anyone whose role (or an individual grant) includes catalog.contribute. */
    public function create(User $user): bool
    {
        return $user->can('catalog.manage') || ($user->tenant_id !== null && $user->can('catalog.contribute'));
    }

    /**
     * Contributors may change only their own organization's entries that are not yet shared;
     * approved medicines are used by every pharmacy and doctor, so only the platform team edits them.
     */
    public function update(User $user, Medicine $medicine): bool
    {
        return $user->can('catalog.manage')
            || ($user->can('catalog.contribute') && $user->tenant_id !== null
                && $medicine->created_by_tenant_id === $user->tenant_id && ! $medicine->isApproved());
    }

    public function delete(User $user, Medicine $medicine): bool
    {
        return $this->update($user, $medicine);
    }

    public function review(User $user, Medicine $medicine): bool
    {
        return $user->can('catalog.manage');
    }
}
