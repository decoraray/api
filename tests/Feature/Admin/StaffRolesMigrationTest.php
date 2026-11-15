<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffRolesMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected string $migrationPath = 'database/migrations/2026_11_16_000000_seed_staff_roles_and_permissions.php';

    public function test_roles_and_permissions_are_seeded_idempotently()
    {
        $expected = [
            'Admin' => ['catalog.view', 'orders.view', 'prices.view'],
            'Sales' => ['catalog.view', 'orders.view', 'orders.update-status', 'orders.invoice', 'customers.view', 'prices.view', 'prices.wholesale', 'dashboard.view'],
            'Technic' => ['catalog.view', 'orders.view', 'orders.update-status', 'dashboard.view'],
        ];

        // Run the migration twice (via refresh) to assert firstOrCreate / syncPermissions idempotency.
        Artisan::call('migrate:refresh', ['--path' => $this->migrationPath, '--force' => true]);
        Artisan::call('migrate:refresh', ['--path' => $this->migrationPath, '--force' => true]);

        foreach ($expected as $roleName => $permissions) {
            $role = Role::findByName($roleName);
            $this->assertEqualsCanonicalizing($permissions, $role->permissions->pluck('name')->toArray());
        }
    }

    public function test_down_leaves_legacy_admin_and_superadmin_untouched()
    {
        Artisan::call('migrate', ['--path' => $this->migrationPath, '--force' => true]);

        $adminUser = User::factory()->create();
        $adminUser->assignRole('Admin');

        $superAdminUser = User::factory()->create();
        $superAdminUser->assignRole('SuperAdmin');

        Artisan::call('migrate:rollback', ['--path' => $this->migrationPath, '--force' => true]);

        $this->assertTrue($adminUser->fresh()->hasRole('Admin'));
        $this->assertTrue($superAdminUser->fresh()->hasRole('SuperAdmin'));
        $this->assertNull(Role::where('name', 'Sales')->first());
        $this->assertNull(Role::where('name', 'Technic')->first());
        $this->assertEmpty(Permission::where('name', 'catalog.edit')->get());

        Artisan::call('migrate', ['--path' => $this->migrationPath, '--force' => true]);

        $this->assertNotNull(Role::where('name', 'Sales')->first());
        $this->assertNotNull(Role::where('name', 'Technic')->first());
        $this->assertEqualsCanonicalizing(
            ['catalog.view', 'orders.view', 'prices.view'],
            Role::findByName('Admin')->permissions->pluck('name')->toArray()
        );
    }
}
