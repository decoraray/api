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

        Permission::firstOrCreate(['name' => 'production.view', 'guard_name' => $guard]);
        Permission::firstOrCreate(['name' => 'production.update', 'guard_name' => $guard]);

        // SuperAdmin passes every gate. The workshop (Technic) moves work orders; Sales only follows them.
        Role::firstOrCreate(['name' => 'Technic', 'guard_name' => $guard])->givePermissionTo(['production.view', 'production.update']);
        Role::firstOrCreate(['name' => 'Sales', 'guard_name' => $guard])->givePermissionTo('production.view');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        Permission::where('guard_name', $guard)->whereIn('name', ['production.view', 'production.update'])->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
