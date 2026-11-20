<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** payments.void: no role gets it; SuperAdmin passes every check through Gate::before. */
return new class extends Migration
{
    public function up(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');
        Permission::firstOrCreate(['name' => 'payments.void', 'guard_name' => $guard]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');
        Permission::where('guard_name', $guard)->where('name', 'payments.void')->get()->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
