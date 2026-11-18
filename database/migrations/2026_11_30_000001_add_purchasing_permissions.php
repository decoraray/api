<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = ['purchasing.request', 'purchasing.receive', 'purchasing.view', 'purchasing.manage'];

    public function up(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        foreach ($this->permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }
        foreach (['inventory.view', 'costs.view'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        // The buyer sees prices; the warehouse counts deliveries without them; the workshop can only ask.
        Role::firstOrCreate(['name' => 'Purchasing', 'guard_name' => $guard])
            ->givePermissionTo([...$this->permissions, 'inventory.view', 'costs.view']);
        Role::firstOrCreate(['name' => 'Warehouse', 'guard_name' => $guard])->givePermissionTo(['purchasing.request', 'purchasing.receive']);
        Role::firstOrCreate(['name' => 'Technic', 'guard_name' => $guard])->givePermissionTo('purchasing.request');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        Permission::where('guard_name', $guard)->whereIn('name', $this->permissions)->get()->each->delete();
        Role::where('guard_name', $guard)->where('name', 'Purchasing')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
