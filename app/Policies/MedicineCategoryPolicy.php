<?php

namespace App\Policies;

use App\Models\MedicineCategory;
use App\Models\User;

class MedicineCategoryPolicy
{
    public function create(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function update(User $user, MedicineCategory $category): bool
    {
        return $user->can('catalog.manage');
    }

    public function delete(User $user, MedicineCategory $category): bool
    {
        return $user->can('catalog.manage');
    }
}
