<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperAdminGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_passes_any_permission_without_being_assigned()
    {
        $user = User::factory()->create();
        $user->assignRole('SuperAdmin');

        $this->assertTrue($user->can('finance.view'));

        $permission = Permission::create(['name' => 'made.up.permission.for.gate.test']);
        $this->assertTrue($user->can($permission->name));
    }

    public function test_plain_superadmin_admin_request_still_works()
    {
        $user = User::factory()->create();
        $user->assignRole('SuperAdmin');

        $response = $this->getJson('/api/v1/admin/dashboard/statistics', [
            'Authorization' => 'Bearer ' . AuthService::createToken($user),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('revenue.total', fn () => true);
    }
}
