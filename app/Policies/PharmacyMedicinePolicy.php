<?php

namespace App\Policies;

use App\Models\PharmacyMedicine;
use App\Models\User;

/**
 * Inventory listings. Tenant ownership is checked here in addition to the global scope (defence in depth).
 */
class PharmacyMedicinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view');
    }

    public function view(User $user, PharmacyMedicine $listing): bool
    {
        return $user->can('inventory.view') && $user->belongsToTenant($listing->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.manage');
    }

    public function update(User $user, PharmacyMedicine $listing): bool
    {
        return $user->can('inventory.manage') && $user->belongsToTenant($listing->tenant_id);
    }

    public function delete(User $user, PharmacyMedicine $listing): bool
    {
        return $this->update($user, $listing);
    }

    /** Receiving stock and adjusting batches. */
    public function manageStock(User $user, PharmacyMedicine $listing): bool
    {
        return $this->update($user, $listing);
    }
}
