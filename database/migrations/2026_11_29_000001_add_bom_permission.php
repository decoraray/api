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

        Permission::firstOrCreate(['name' => 'bom.manage', 'guard_name' => $guard]);
        // SuperAdmin passes every gate. The workshop writes the BOMs; work-order materials use production.*.
        Role::firstOrCreate(['name' => 'Technic', 'guard_name' => $guard])->givePermissionTo('bom.manage');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        Permission::where('guard_name', $guard)->where('name', 'bom.manage')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
