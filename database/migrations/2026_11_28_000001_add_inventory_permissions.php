<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = ['inventory.view', 'inventory.manage', 'costs.view'];

    public function up(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        foreach ($this->permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        // SuperAdmin passes every gate. costs.view (unit cost, stock value, later profit) goes to no role here.
        Role::firstOrCreate(['name' => 'Warehouse', 'guard_name' => $guard])->givePermissionTo(['inventory.view', 'inventory.manage']);
        Role::firstOrCreate(['name' => 'Technic', 'guard_name' => $guard])->givePermissionTo('inventory.view');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        Permission::where('guard_name', $guard)->whereIn('name', $this->permissions)->get()->each->delete();
        Role::where('guard_name', $guard)->where('name', 'Warehouse')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
