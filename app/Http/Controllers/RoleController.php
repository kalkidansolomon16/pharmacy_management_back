<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\PermissionCatalog;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles & permissions.
 *  - Platform team: sees and edits the default permissions of the built-in roles (they apply everywhere).
 *  - Organization admins: see the built-in roles they can use (read-only) and design their own custom roles,
 *    choosing only from permissions they hold themselves and that fit their organization.
 * Nothing here can hand out platform powers to an organization, or lock an admin out.
 */
class RoleController extends Controller
{
    private const MAX_CUSTOM_ROLES = 30;

    public function __construct(private ActivityLogger $logger) {}

    public function index(Request $request)
    {
        Gate::authorize('roles.manage');
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            $roles = Role::whereNull('tenant_id')->where('name', '!=', User::ROLE_CUSTOMER)->withCount('users')->with('permissions')->get();
        } else {
            $assignable = User::assignableRolesFor($user->tenant);
            $roles = Role::where(fn ($q) => $q->whereNull('tenant_id')->whereIn('name', $assignable)
                ->orWhere('tenant_id', $user->tenant_id))
                ->withCount(['users' => fn ($q) => $q->where('tenant_id', $user->tenant_id)])
                ->with('permissions')->get();
        }

        return $this->respond([
            'roles' => $roles->sortBy(fn (Role $r) => [$r->isCustom() ? 1 : 0, $r->displayName()])->values()->map(fn (Role $r) => $this->present($r, $user)),
            'catalog' => $this->catalogFor($user),
            'grantable' => $this->grantable($user),
            'max_custom_roles' => self::MAX_CUSTOM_ROLES,
        ]);
    }

    /** Organization admins create roles for their own organization. */
    public function store(Request $request)
    {
        Gate::authorize('roles.manage');
        $user = $request->user();
        abort_if($user->isSuperAdmin() || ! $user->tenant_id, 422, 'Custom roles belong to an organization. The platform team edits the built-in roles instead.');

        $data = $this->validated($request, $user);
        if (Role::where('tenant_id', $user->tenant_id)->count() >= self::MAX_CUSTOM_ROLES) {
            throw BusinessRuleException::withErrors('label', 'You already have '.self::MAX_CUSTOM_ROLES.' custom roles. Edit or delete one first.');
        }
        if (Role::where('tenant_id', $user->tenant_id)->whereRaw('lower(label) = ?', [mb_strtolower($data['label'])])->exists()) {
            throw BusinessRuleException::withErrors('label', 'You already have a role with that name.');
        }

        $role = DB::transaction(function () use ($user, $data) {
            $role = Role::create([
                'name' => 'org'.$user->tenant_id.'_'.Str::slug($data['label'], '_').'_'.Str::lower(Str::random(4)),
                'guard_name' => 'web', 'tenant_id' => $user->tenant_id,
                'label' => $data['label'], 'description' => $data['description'] ?? null,
            ]);
            $role->syncPermissions($data['permissions']);

            return $role;
        });
        $this->logger->log('role_created', $user, ['role' => $role->label, 'permissions' => $data['permissions']], "Created role {$role->label}");

        return $this->respond($this->present($role->load('permissions'), $user), "Role “{$role->label}” created. Give it to people on the Team page.", 201);
    }

    public function update(Request $request, Role $role)
    {
        Gate::authorize('roles.manage');
        $user = $request->user();
        $this->ensureEditable($role, $user);

        $data = $this->validated($request, $user, $role);
        // Built-in roles keep the permissions that stop anyone being locked out
        $permissions = array_values(array_unique([...$data['permissions'], ...(PermissionCatalog::LOCKED[$role->name] ?? [])]));

        $before = $role->permissions->pluck('name')->all();
        DB::transaction(function () use ($role, $data, $permissions) {
            if ($role->isCustom()) {
                $role->update(['label' => $data['label'], 'description' => $data['description'] ?? null]);
            }
            $role->syncPermissions($permissions);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->logger->log('role_updated', $user, [
            'role' => $role->displayName(),
            'added' => array_values(array_diff($permissions, $before)),
            'removed' => array_values(array_diff($before, $permissions)),
        ], "Changed permissions of {$role->displayName()}");

        return $this->respond($this->present($role->refresh()->load('permissions'), $user),
            $role->isCustom() ? 'Role saved.' : 'Default permissions saved. They apply to every organization using this role.');
    }

    public function destroy(Request $request, Role $role)
    {
        Gate::authorize('roles.manage');
        $user = $request->user();
        abort_unless($role->isCustom(), 422, 'Built-in roles cannot be deleted.');
        $this->ensureEditable($role, $user);
        if ($role->users()->exists()) {
            throw BusinessRuleException::withErrors('role', 'People still have this role. Give them another role on the Team page first.');
        }

        $label = $role->label;
        $role->delete();
        $this->logger->log('role_deleted', $user, ['role' => $label], "Deleted role {$label}");

        return $this->respond(null, "Role “{$label}” deleted.");
    }

    // ------------------------------------------------------------------------------------------

    private function ensureEditable(Role $role, User $user): void
    {
        $ok = $user->isSuperAdmin()
            ? ! $role->isCustom() && $role->name !== User::ROLE_CUSTOMER
            : $role->isCustom() && $role->tenant_id === $user->tenant_id;

        abort_unless($ok, 403, $user->isSuperAdmin()
            ? 'Custom roles belong to their organization.'
            : 'Built-in roles are set by MedLink. Create a custom role to change what people can do.');
    }

    private function validated(Request $request, User $user, ?Role $role = null): array
    {
        $grantable = $this->grantable($user, $role);
        $custom = ! $role || $role->isCustom();

        return $request->validate([
            'label' => [$custom ? 'required' : 'nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:191'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', \Illuminate\Validation\Rule::in($grantable)],
        ], [
            'permissions.*.in' => 'You cannot give a permission you do not hold yourself, or one that does not apply to your organization.',
        ]);
    }

    /**
     * What this person may put into a role.
     *  - Platform team editing a built-in role: platform permissions for the platform role, organization ones for the others.
     *  - Organization admin: organization permissions for their switched-on modules that they hold themselves.
     */
    private function grantable(User $user, ?Role $role = null): array
    {
        if ($user->isSuperAdmin()) {
            if ($role?->name === User::ROLE_SUPER_ADMIN) {
                return [...PermissionCatalog::names('platform'), 'users.view', 'users.create', 'users.update', 'users.delete', 'roles.manage', 'reports.view', 'activity.view'];
            }

            return PermissionCatalog::names('organization');
        }

        return array_values(array_filter(PermissionCatalog::forOrganization($user->tenant), fn ($p) => $user->can($p)));
    }

    /** Catalogue groups relevant to this person, with the labels the editor shows. */
    private function catalogFor(User $user): array
    {
        $modules = $user->tenant?->enabledModules();

        return collect(PermissionCatalog::GROUPS)
            ->filter(fn ($g) => $g['scope'] !== 'customer')
            ->filter(fn ($g) => $user->isSuperAdmin() || ($g['scope'] === 'organization' && (! isset($g['module']) || in_array($g['module'], $modules ?? [], true))))
            ->map(fn ($g, $key) => [
                'key' => $key, 'label' => $g['label'], 'scope' => $g['scope'], 'module' => $g['module'] ?? null,
                'permissions' => collect($g['permissions'])->map(fn ($p, $name) => ['name' => $name, 'label' => $p[0], 'action' => $p[1]])->values(),
            ])->values()->all();
    }

    private function present(Role $role, User $viewer): array
    {
        $editable = $viewer->isSuperAdmin()
            ? ! $role->isCustom()
            : $role->isCustom() && $role->tenant_id === $viewer->tenant_id;

        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->displayName(),
            'description' => $role->description,
            'is_custom' => $role->isCustom(),
            'editable' => $editable,
            'users_count' => $role->users_count ?? $role->users()->count(),
            'permissions' => $role->permissions->pluck('name')->values(),
            'locked' => PermissionCatalog::LOCKED[$role->name] ?? [],
            'defaults' => $role->isCustom() ? null : (Permissions::MATRIX[$role->name] ?? null),
        ];
    }
}
