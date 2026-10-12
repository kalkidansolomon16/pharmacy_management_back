<?php

namespace App\Policies;

use App\Models\MedicineCategory;
use App\Models\User;

class MedicineCategoryPolicy
{
    public function create(User $user): bool
    {
        return $user->can('catalog.manage') || ($user->tenant_id !== null && $user->can('catalog.contribute'));
    }

    public function update(User $user, MedicineCategory $category): bool
    {
        return $user->can('catalog.manage')
            || ($user->can('catalog.contribute') && $user->tenant_id !== null
                && $category->created_by_tenant_id === $user->tenant_id && $category->review_status !== 'approved');
    }

    public function delete(User $user, MedicineCategory $category): bool
    {
        return $this->update($user, $category);
    }

    public function review(User $user, MedicineCategory $category): bool
    {
        return $user->can('catalog.manage');
    }
}
