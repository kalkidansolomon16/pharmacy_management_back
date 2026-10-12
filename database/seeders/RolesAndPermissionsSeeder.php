<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission and the built-in roles. Safe to re-run on a live system: it never takes
 * away permissions the platform team changed in Roles & permissions. A role created now gets its
 * defaults; an existing role only receives permissions that are brand new in this release.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $new = [];
        foreach (Permissions::all() as $permission) {
            if (! Permission::where('name', $permission)->where('guard_name', 'web')->exists()) {
                Permission::create(['name' => $permission, 'guard_name' => 'web']);
                $new[] = $permission;
            }
        }

        foreach (Permissions::MATRIX as $name => $permissions) {
            $role = Role::whereNull('tenant_id')->where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                Role::create(['name' => $name, 'guard_name' => 'web'])->syncPermissions($permissions);

                continue;
            }
            $additions = array_values(array_intersect($permissions, $new));
            if ($additions) {
                $role->givePermissionTo($additions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
