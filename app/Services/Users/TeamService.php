<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class TeamService
{
    public function __construct(private ActivityLogger $logger) {}

    public function create(User $actor, array $data): User
    {
        $tenant = $actor->isSuperAdmin() ? \App\Models\Tenant::find($data['tenant_id'] ?? null) : $actor->tenant;
        // The plan's staff-account limit; the platform team may add accounts beyond it as an exception
        if ($tenant && ! $actor->isSuperAdmin() && ($data['status'] ?? 'active') === 'active') {
            app(\App\Services\Platform\PlanLimits::class)->ensureCanAdd($tenant, 'users');
        }

        return DB::transaction(function () use ($actor, $data) {
            $user = User::create([
                ...Arr::except($data, ['role', 'permissions']),
                // Tenant admins can only add people to their own organization
                'tenant_id' => $actor->isSuperAdmin() ? ($data['tenant_id'] ?? null) : $actor->tenant_id,
                'phone' => ReferenceGenerator::normalizePhone($data['phone'] ?? null),
                'status' => $data['status'] ?? 'active',
            ]);
            $user->assignRole($data['role']);
            $this->syncExtraPermissions($user, $data);

            $this->logger->log('user_created', $user, ['role' => $data['role'], 'email' => $user->email], "Added {$user->name} as {$data['role']}");

            return $user->load('roles', 'tenant', 'permissions');
        });
    }

    public function update(User $user, array $data): User
    {
        // Re-activating someone takes a staff account again
        if ($user->status !== 'active' && ($data['status'] ?? $user->status) === 'active' && $user->tenant && ! auth()->user()?->isSuperAdmin()) {
            app(\App\Services\Platform\PlanLimits::class)->ensureCanAdd($user->tenant, 'users');
        }

        return DB::transaction(function () use ($user, $data) {
            $attributes = Arr::except($data, ['role', 'tenant_id', 'permissions']);
            $attributes['phone'] = ReferenceGenerator::normalizePhone($data['phone'] ?? null);
            if (empty($attributes['password'])) {
                unset($attributes['password']);
            }

            $user->update($attributes);
            $user->syncRoles([$data['role']]);
            $this->syncExtraPermissions($user, $data);

            if (($attributes['status'] ?? 'active') !== 'active') {
                $user->tokens()->delete();
            }

            $this->logger->log('user_updated', $user, ['role' => $data['role'], 'status' => $user->status, 'extra_permissions' => $data['permissions'] ?? null]);

            return $user->load('roles', 'tenant', 'permissions');
        });
    }

    /**
     * Individually granted permissions (role permissions are untouched). Only the grantable list is managed here,
     * so a request can never hand out anything else.
     */
    private function syncExtraPermissions(User $user, array $data): void
    {
        if (! array_key_exists('permissions', $data)) {
            return;
        }
        $grantable = array_keys(\App\Support\Permissions::GRANTABLE);
        $keep = $user->getDirectPermissions()->pluck('name')->diff($grantable)->all();
        $user->syncPermissions([...$keep, ...array_intersect($data['permissions'] ?? [], $grantable)]);
    }

    /** Users are never hard-deleted: their name stays on orders, prescriptions and the audit trail. */
    public function deactivate(User $user): void
    {
        $user->update(['status' => 'inactive']);
        $user->tokens()->delete();
        $this->logger->log('user_deactivated', $user);
    }
}
