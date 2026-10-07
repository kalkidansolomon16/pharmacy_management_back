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
            ->with('roles', 'tenant')
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

        return UserResource::collection($users);
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
