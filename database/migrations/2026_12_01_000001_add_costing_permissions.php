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

        foreach (['costs.view', 'costs.manage'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        // Accounting sees and enters costs and profit, and reads what they come from.
        $grants = ['costs.view', 'costs.manage', 'finance.view', 'prices.view', 'orders.view', 'inventory.view', 'purchasing.view'];
        $existing = Permission::where('guard_name', $guard)->whereIn('name', $grants)->pluck('name')->all();
        Role::firstOrCreate(['name' => 'Accounting', 'guard_name' => $guard])->givePermissionTo($existing);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        Permission::where('guard_name', $guard)->where('name', 'costs.manage')->get()->each->delete();
        Role::where('guard_name', $guard)->where('name', 'Accounting')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
