<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        // Given to no role: SuperAdmin passes every gate, and mass SMS stays with the owner.
        Permission::firstOrCreate(['name' => 'campaigns.manage', 'guard_name' => $guard]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        Permission::where('guard_name', $guard)->where('name', 'campaigns.manage')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
