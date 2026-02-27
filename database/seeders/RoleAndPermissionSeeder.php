<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Permission::create(['name'=>'user-create']);
        Permission::create(['name'=>'user-update']);
        Permission::create(['name'=>'user-delete']);
        Permission::create(['name'=>'user-read']);

        Permission::create(['name'=>'tenant-create']);
        Permission::create(['name'=>'tenant-update']);
        Permission::create(['name'=>'tenant-delete']);
        Permission::create(['name'=>'tenant-read']);

        Permission::create(['name'=>'medicine-category-create']);
        Permission::create(['name'=>'medicine-category-update']);
        Permission::create(['name'=>'medicine-category-delete']);
        Permission::create(['name'=>'medicine-category-read']);

       $super_admin = Role::create(['name'=>'super_admin']);
        $super_admin->givePermissionTo(Permission::all());
       $pharmacy_admin = Role::create(['name'=>'pharmacy_admin']);
       $staff = Role::create(['name'=>'staff']);
       $doctor = Role::create(['name'=>'doctor']);
    }
}
