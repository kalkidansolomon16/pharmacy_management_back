<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Visit;
use App\Policies\Concerns\ChecksTenantPermissions;

class VisitPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->any($user, 'visits.view');
    }

    public function view(User $user, Visit $model): bool
    {
        return $this->own($user, $model, 'visits.view');
    }

    public function create(User $user): bool
    {
        return $this->any($user, 'visits.manage');
    }

    public function update(User $user, Visit $model): bool
    {
        return $this->own($user, $model, 'visits.manage');
    }

    public function triage(User $user, Visit $visit): bool
    {
        return $this->own($user, $visit, 'triage.record', 'consultations.manage');
    }

    public function consult(User $user, Visit $visit): bool
    {
        return $this->own($user, $visit, 'consultations.manage');
    }

    /** Clinical notes: only clinicians, not reception or cashiers. */
    public function viewClinical(User $user, Visit $visit): bool
    {
        return $this->own($user, $visit, 'consultations.manage', 'triage.record');
    }
}
