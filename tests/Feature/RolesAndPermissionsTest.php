<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Role-based access: custom organization roles, built-in role defaults, and specific CRUD permissions. */
class RolesAndPermissionsTest extends TestCase
{
    public function test_a_pharmacy_admin_designs_a_custom_role_and_gives_it_to_staff(): void
    {
        $pharmacy = $this->tenant('pharmacy');
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $pharmacy);
        $listing = $this->listing($pharmacy);

        $role = $this->actingAs($admin)->postJson('/api/roles', [
            'label' => 'Store keeper', 'description' => 'Receives deliveries',
            'permissions' => ['inventory.view', 'inventory.update', 'inventory.stock'],
        ])->assertCreated()->assertJsonPath('data.label', 'Store keeper')->json('data');

        // The Team page offers it, and it can be given to a new member
        $this->actingAs($admin)->getJson('/api/users')->assertJsonPath("meta.role_labels.{$role['name']}", 'Store keeper');
        $keeper = $this->actingAs($admin)->postJson('/api/users', ['name' => 'Kebede', 'email' => 'kebede@test.et', 'role' => $role['name'], 'password' => 'Secret123'])
            ->assertCreated()->assertJsonPath('data.role_label', 'Store keeper')->json('data');
        $keeper = User::find($keeper['id']);

        // Exactly what the role allows
        $this->actingAs($keeper)->putJson("/api/inventory/{$listing->id}", ['price' => 12, 'reorder_level' => 5, 'is_public' => true])->assertOk();
        $this->actingAs($keeper)->deleteJson("/api/inventory/{$listing->id}")->assertForbidden();
        $this->actingAs($keeper)->postJson('/api/pos/sales', ['items' => [['pharmacy_medicine_id' => $listing->id, 'quantity' => 1]], 'payment_method' => 'cash'])->assertForbidden();
        $this->actingAs($keeper)->getJson('/api/users')->assertForbidden();
    }

    public function test_the_roles_page_works_with_real_api_tokens(): void
    {
        // Real requests authenticate through Sanctum, which changes the active auth guard
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $this->tenant('pharmacy'));
        \Laravel\Sanctum\Sanctum::actingAs($admin, ['*']);

        $roles = collect($this->getJson('/api/roles')->assertOk()->json('data.roles'))->keyBy('name');
        $this->assertSame(1, $roles[User::ROLE_PHARMACY_ADMIN]['users_count']);
    }

    public function test_admins_cannot_hand_out_powers_they_do_not_have_or_that_do_not_fit_their_organization(): void
    {
        $pharmacy = $this->tenant('pharmacy');
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $pharmacy);

        foreach (['tenants.manage', 'catalog.manage', 'platform.plans', 'patients.view', 'made.up'] as $permission) {
            $this->actingAs($admin)->postJson('/api/roles', ['label' => 'Sneaky '.$permission, 'permissions' => ['inventory.view', $permission]])
                ->assertUnprocessable()->assertJsonValidationErrors('permissions.1');
        }

        // A pharmacist cannot design roles at all
        $this->actingAs($this->userWithRole(User::ROLE_STAFF, $pharmacy))->getJson('/api/roles')->assertForbidden();
    }

    public function test_organizations_only_edit_their_own_custom_roles(): void
    {
        $mine = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $this->tenant('pharmacy'));
        $other = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $this->tenant('pharmacy'));
        $role = $this->actingAs($other)->postJson('/api/roles', ['label' => 'Night shift', 'permissions' => ['sales.create']])->json('data');
        $staff = Role::where('name', User::ROLE_STAFF)->first();

        $this->actingAs($mine)->putJson("/api/roles/{$role['id']}", ['label' => 'Hijack', 'permissions' => []])->assertForbidden();
        $this->actingAs($mine)->deleteJson("/api/roles/{$role['id']}")->assertForbidden();
        $this->actingAs($mine)->putJson("/api/roles/{$staff->id}", ['permissions' => ['sales.create']])->assertForbidden(); // built-in: set by MedLink
        $this->actingAs($mine)->getJson('/api/roles')->assertJsonMissing(['label' => 'Night shift']);
    }

    public function test_the_platform_admin_changes_built_in_defaults_but_cannot_lock_admins_out(): void
    {
        $platform = $this->userWithRole(User::ROLE_SUPER_ADMIN);
        $pharmacy = $this->tenant('pharmacy');
        $pharmacist = $this->userWithRole(User::ROLE_STAFF, $pharmacy);
        $staff = Role::where('name', User::ROLE_STAFF)->first();
        $admin = Role::where('name', User::ROLE_PHARMACY_ADMIN)->first();

        $this->assertTrue($pharmacist->can('orders.manage'));
        $this->actingAs($platform)->putJson("/api/roles/{$staff->id}", ['permissions' => ['inventory.view', 'sales.create', 'orders.view']])->assertOk();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($pharmacist->fresh()->can('orders.manage'));

        // Removing everything from the admin role still leaves the lock-out protection
        $this->actingAs($platform)->putJson("/api/roles/{$admin->id}", ['permissions' => []])
            ->assertOk()->assertJsonPath('data.permissions', ['users.view', 'users.create', 'users.update', 'roles.manage', 'organization.manage']);

        // Platform powers never go into an organization role
        $this->actingAs($platform)->putJson("/api/roles/{$staff->id}", ['permissions' => ['tenants.manage']])->assertUnprocessable();
    }

    public function test_a_role_still_in_use_cannot_be_deleted(): void
    {
        $pharmacy = $this->tenant('pharmacy');
        $admin = $this->userWithRole(User::ROLE_PHARMACY_ADMIN, $pharmacy);
        $role = $this->actingAs($admin)->postJson('/api/roles', ['label' => 'Trainee', 'permissions' => ['inventory.view']])->json('data');
        $trainee = $this->userWithRole($role['name'], $pharmacy);

        $this->actingAs($admin)->deleteJson("/api/roles/{$role['id']}")->assertUnprocessable();
        $trainee->syncRoles([User::ROLE_STAFF]);
        $this->actingAs($admin)->deleteJson("/api/roles/{$role['id']}")->assertOk();
        $this->assertNull(Role::find($role['id']));
    }

    public function test_crud_permissions_are_checked_separately(): void
    {
        $hospital = $this->tenant('hospital');
        $admin = $this->userWithRole(User::ROLE_HOSPITAL_ADMIN, $hospital);
        $role = $this->actingAs($admin)->postJson('/api/roles', ['label' => 'Records clerk', 'permissions' => ['patients.view', 'patients.create']])->json('data');
        $clerk = $this->userWithRole($role['name'], $hospital);
        $patient = $this->patient($hospital);

        $this->actingAs($clerk)->postJson('/api/patients', ['full_name' => 'Hana Bekele', 'phone' => '0911555666', 'gender' => 'female'])->assertCreated();
        $this->actingAs($clerk)->putJson("/api/patients/{$patient->id}", ['full_name' => 'Changed', 'phone' => '0911223344', 'gender' => 'male'])->assertForbidden();
    }

    public function test_old_all_in_one_permissions_are_split_without_anyone_losing_access(): void
    {
        // Simulate a database from before the split: a role with the old "inventory.manage"
        $legacy = Permission::create(['name' => 'inventory.manage', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'legacy_role', 'guard_name' => 'web']);
        $role->givePermissionTo($legacy);
        $person = $this->userWithRole('legacy_role', $this->tenant('pharmacy'));
        $person->givePermissionTo($legacy);

        (require database_path('migrations/2026_10_10_000004_add_custom_roles_and_granular_permissions.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(Permission::where('name', 'inventory.manage')->exists());
        foreach (['inventory.create', 'inventory.update', 'inventory.delete', 'inventory.stock', 'purchasing.view'] as $permission) {
            $this->assertTrue($role->fresh()->hasPermissionTo($permission), $permission);
            $this->assertTrue($person->fresh()->hasDirectPermission($permission), $permission);
        }
    }
}
