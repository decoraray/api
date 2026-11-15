<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        Permission::firstOrCreate(['name' => 'leads.manage', 'guard_name' => $guard]);
        Role::firstOrCreate(['name' => 'Sales', 'guard_name' => $guard])->givePermissionTo('leads.manage');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        Permission::where('guard_name', $guard)->where('name', 'leads.manage')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
