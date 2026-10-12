<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Roles can belong to one organization (custom roles) and carry a display label + description.
 * 2. All-in-one "*.manage" permissions are split into specific ones (create / update / delete ...).
 *    Every role and person that had the old permission gets all of its replacements, so nobody
 *    loses access; then the old permission is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'tenant_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->foreignId('tenant_id')->nullable()->after('guard_name')->constrained('tenants')->cascadeOnDelete();
                $table->string('label', 80)->nullable()->after('tenant_id');
                $table->string('description', 191)->nullable()->after('label');
            });
        }

        $now = now();
        foreach (PermissionCatalog::SPLITS as $old => $replacements) {
            $oldId = DB::table('permissions')->where('name', $old)->where('guard_name', 'web')->value('id');
            if (! $oldId) {
                continue; // fresh database: the seeder creates the new permissions directly
            }

            $newIds = [];
            foreach ($replacements as $name) {
                $newIds[] = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')
                    ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
            }

            foreach (DB::table('role_has_permissions')->where('permission_id', $oldId)->pluck('role_id') as $roleId) {
                foreach ($newIds as $permissionId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
            }
            foreach (DB::table('model_has_permissions')->where('permission_id', $oldId)->get() as $row) {
                foreach ($newIds as $permissionId) {
                    DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'model_type' => $row->model_type, 'model_id' => $row->model_id]);
                }
            }

            DB::table('role_has_permissions')->where('permission_id', $oldId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $oldId)->delete();
            DB::table('permissions')->where('id', $oldId)->delete();
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['label', 'description']);
        });
    }
};
