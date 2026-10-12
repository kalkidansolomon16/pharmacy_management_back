<?php

namespace App\Http\Controllers;

use App\Http\Requests\Users\SaveUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Users\TeamService;
use Illuminate\Http\Request;

/**
 * Team management. Super admins see everyone; tenant admins only their own organization.
 */
class UserController extends Controller
{
    public function __construct(private TeamService $team) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);
        $actor = $request->user();

        $users = User::query()
            ->with('roles', 'tenant', 'permissions')
            ->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('tenant_id', $actor->tenant_id))
            ->when($request->query('tenant_id') && $actor->isSuperAdmin(), fn ($q) => $q->where('tenant_id', $request->query('tenant_id')))
            ->when($request->query('role'), fn ($q, $role) => $q->role($role))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")))
            ->latest()
            ->paginate($this->perPage());

        // Extra permissions this admin may give individual team members (only ones the admin holds)
        $grantable = collect(\App\Support\Permissions::GRANTABLE)
            ->filter(fn ($label, $permission) => $actor->isSuperAdmin() || $actor->can($permission))
            ->map(fn ($label, $permission) => ['value' => $permission, 'label' => $label])
            ->values();

        return UserResource::collection($users)->additional(['meta' => [
            'grantable_permissions' => $grantable,
            // Staff accounts used / allowed by the plan (null limit = not limited)
            'seats' => $actor->tenant ? app(\App\Services\Platform\PlanLimits::class)->usage($actor->tenant)['users'] : null,
            // Roles this admin may give in their organization (depends on its type and switched-on modules)
            'assignable_roles' => $actor->tenant ? User::assignableRolesFor($actor->tenant) : [],
            'role_labels' => \App\Models\Role::whereIn('name', $actor->tenant ? User::assignableRolesFor($actor->tenant) : [])->get()->mapWithKeys(fn ($r) => [$r->name => $r->displayName()]),
            // Which of the grantable permissions each role already includes
            // (read from the live roles, which the platform team and admins can edit)
            'role_includes' => collect(array_keys(\App\Support\Permissions::GRANTABLE))
                ->mapWithKeys(fn ($p) => [$p => \App\Models\Role::whereHas('permissions', fn ($q) => $q->where('name', $p))->pluck('name')->values()]),
        ]]);
    }

    public function store(SaveUserRequest $request)
    {
        $this->authorize('create', User::class);
        $user = $this->team->create($request->user(), $request->validated());

        return $this->respond(new UserResource($user), "{$user->name} has been added to the team.", 201);
    }

    public function update(SaveUserRequest $request, User $user)
    {
        $this->authorize('update', $user);
        $user = $this->team->update($user, $request->validated());

        return $this->respond(new UserResource($user), 'User updated.');
    }

    public function destroy(User $user)
    {
        $this->authorize('delete', $user);
        $this->team->deactivate($user);

        return $this->respond(null, "{$user->name} has been deactivated and signed out.");
    }
}
