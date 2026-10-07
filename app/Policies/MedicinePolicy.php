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

    public function create(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function update(User $user, Medicine $medicine): bool
    {
        return $user->can('catalog.manage');
    }

    public function delete(User $user, Medicine $medicine): bool
    {
        return $user->can('catalog.manage');
    }
}
