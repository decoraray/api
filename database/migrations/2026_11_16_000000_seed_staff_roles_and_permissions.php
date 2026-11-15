<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The guard used by the existing permission system.
     */
    protected string $guardName;

    /**
     * The 14 permissions introduced by this migration.
     *
     * @var array<int, string>
     */
    protected array $permissionNames = [
        'catalog.view',
        'catalog.edit',
        'orders.view',
        'orders.update-status',
        'orders.invoice',
        'customers.view',
        'customers.edit',
        'prices.view',
        'prices.wholesale',
        'finance.view',
        'dashboard.view',
        'blog.manage',
        'staff.manage',
        'audit.view',
    ];

    /**
     * The two new staff roles introduced by this migration.
     *
     * @var array<int, string>
     */
    protected array $newRoleNames = ['Sales', 'Technic'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->guardName = config('permission.defaults.guard_name', 'web');

        $permissions = $this->createPermissions();
        $roles = $this->createRoles();

        $this->syncRolePermissions($roles, $permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     *
     * Leaves the legacy Admin role and all SuperAdmin assignments untouched.
     */
    public function down(): void
    {
        $this->guardName = config('permission.defaults.guard_name', 'web');

        // 1. Remove assignments for the newly-added roles only.
        $newRoleIds = Role::where('guard_name', $this->guardName)
            ->whereIn('name', $this->newRoleNames)
            ->pluck('id')
            ->toArray();

        if (!empty($newRoleIds)) {
            DB::table('role_has_permissions')
                ->whereIn('role_id', $newRoleIds)
                ->delete();

            DB::table('model_has_roles')
                ->whereIn('role_id', $newRoleIds)
                ->delete();

            Role::whereIn('id', $newRoleIds)->delete();
        }

        // 2. Remove the 14 permissions and their assignments, but do not touch
        //    permissions that belong to other roles or other users.
        $permissionIds = Permission::where('guard_name', $this->guardName)
            ->whereIn('name', $this->permissionNames)
            ->pluck('id')
            ->toArray();

        if (!empty($permissionIds)) {
            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();

            DB::table('model_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->delete();

            Permission::whereIn('id', $permissionIds)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Create every permission from the staff matrix.
     *
     * @return array<string, \Spatie\Permission\Models\Permission>
     */
    protected function createPermissions(): array
    {
        $permissions = [];
        foreach ($this->permissionNames as $name) {
            $permissions[$name] = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => $this->guardName,
            ]);
        }

        return $permissions;
    }

    /**
     * Create the bundled staff roles.
     *
     * @return array<string, \Spatie\Permission\Models\Role>
     */
    protected function createRoles(): array
    {
        // SuperAdmin is referenced by Gate::before and by tests; ensure it exists.
        Role::firstOrCreate([
            'name' => 'SuperAdmin',
            'guard_name' => $this->guardName,
        ]);

        $names = array_merge(['Admin'], $this->newRoleNames);

        $roles = [];
        foreach ($names as $name) {
            $roles[$name] = Role::firstOrCreate([
                'name' => $name,
                'guard_name' => $this->guardName,
            ]);
        }

        return $roles;
    }

    /**
     * Assign the permission bundles to each role.
     *
     * @param array<string, \Spatie\Permission\Models\Role> $roles
     * @param array<string, \Spatie\Permission\Models\Permission> $permissions
     */
    protected function syncRolePermissions(array $roles, array $permissions): void
    {
        // Admin (legacy): read-only with prices
        $roles['Admin']->syncPermissions([
            $permissions['catalog.view'],
            $permissions['orders.view'],
            $permissions['prices.view'],
        ]);

        // Sales
        $roles['Sales']->syncPermissions([
            $permissions['catalog.view'],
            $permissions['orders.view'],
            $permissions['orders.update-status'],
            $permissions['orders.invoice'],
            $permissions['customers.view'],
            $permissions['prices.view'],
            $permissions['prices.wholesale'],
            $permissions['dashboard.view'],
        ]);

        // Technic
        $roles['Technic']->syncPermissions([
            $permissions['catalog.view'],
            $permissions['orders.view'],
            $permissions['orders.update-status'],
            $permissions['dashboard.view'],
        ]);
    }
};
