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
        return DB::transaction(function () use ($actor, $data) {
            $user = User::create([
                ...Arr::except($data, ['role']),
                // Tenant admins can only add people to their own organization
                'tenant_id' => $actor->isSuperAdmin() ? ($data['tenant_id'] ?? null) : $actor->tenant_id,
                'phone' => ReferenceGenerator::normalizePhone($data['phone'] ?? null),
                'status' => $data['status'] ?? 'active',
            ]);
            $user->assignRole($data['role']);

            $this->logger->log('user_created', $user, ['role' => $data['role'], 'email' => $user->email], "Added {$user->name} as {$data['role']}");

            return $user->load('roles', 'tenant');
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $attributes = Arr::except($data, ['role', 'tenant_id']);
            $attributes['phone'] = ReferenceGenerator::normalizePhone($data['phone'] ?? null);
            if (empty($attributes['password'])) {
                unset($attributes['password']);
            }

            $user->update($attributes);
            $user->syncRoles([$data['role']]);

            if (($attributes['status'] ?? 'active') !== 'active') {
                $user->tokens()->delete();
            }

            $this->logger->log('user_updated', $user, ['role' => $data['role'], 'status' => $user->status]);

            return $user->load('roles', 'tenant');
        });
    }

    /** Users are never hard-deleted: their name stays on orders, prescriptions and the audit trail. */
    public function deactivate(User $user): void
    {
        $user->update(['status' => 'inactive']);
        $user->tokens()->delete();
        $this->logger->log('user_deactivated', $user);
    }
}
