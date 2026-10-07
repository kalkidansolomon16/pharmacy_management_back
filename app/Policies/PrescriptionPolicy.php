<?php

namespace App\Policies;

use App\Models\Prescription;
use App\Models\User;

class PrescriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('prescriptions.view');
    }

    /** Hospital staff see their hospital's prescriptions; pharmacists see any they look up by code. */
    public function view(User $user, Prescription $prescription): bool
    {
        return ($user->can('prescriptions.view') && $user->belongsToTenant($prescription->tenant_id))
            || $user->can('prescriptions.dispense');
    }

    public function create(User $user): bool
    {
        return $user->can('prescriptions.create');
    }

    /** The prescribing doctor, or their hospital admin. */
    public function cancel(User $user, Prescription $prescription): bool
    {
        if (! $user->belongsToTenant($prescription->tenant_id)) {
            return false;
        }

        return $prescription->doctor_id === $user->id || $user->hasRole(User::ROLE_HOSPITAL_ADMIN);
    }

    public function dispense(User $user): bool
    {
        return $user->can('prescriptions.dispense');
    }
}
