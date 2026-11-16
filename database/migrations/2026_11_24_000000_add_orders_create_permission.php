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

        Permission::firstOrCreate(['name' => 'orders.create', 'guard_name' => $guard]);
        // SuperAdmin passes every gate. Sales takes the orders; Technic only moves them through production.
        Role::firstOrCreate(['name' => 'Sales', 'guard_name' => $guard])->givePermissionTo('orders.create');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        Permission::where('guard_name', $guard)->where('name', 'orders.create')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
