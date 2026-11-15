<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var array<int, string> */
    protected array $permissionNames = ['quotes.manage', 'payments.record', 'settings.manage'];

    /** Permissions Sales receives (settings.manage is SuperAdmin only). */
    protected array $salesPermissions = ['quotes.manage', 'payments.record'];

    public function up(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        foreach ($this->permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        $sales = Role::firstOrCreate(['name' => 'Sales', 'guard_name' => $guard]);
        $sales->givePermissionTo($this->salesPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('permission.defaults.guard_name', 'web');

        // Deleting a permission also detaches it from roles and users.
        Permission::where('guard_name', $guard)
            ->whereIn('name', $this->permissionNames)
            ->get()
            ->each->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
