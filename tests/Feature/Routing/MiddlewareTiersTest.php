<?php

namespace Tests\Feature\Routing;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MiddlewareTiersTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return AuthService::createToken($user);
    }

    public function test_public_route_works_without_a_token()
    {
        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(200);
    }

    public function test_authenticate_only_route_still_works_with_no_token()
    {
        // /api/v1/cart sits behind the outer `Authenticate` tier only, which never rejects.
        $response = $this->getJson('/api/v1/cart');

        $response->assertStatus(200);
    }

    public function test_jwt_protected_route_rejects_missing_token()
    {
        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(401);
    }

    public function test_jwt_protected_route_allows_valid_token()
    {
        $user = User::factory()->create();

        $response = $this->getJson('/api/v1/orders', [
            'Authorization' => 'Bearer ' . $this->tokenFor($user),
        ]);

        $response->assertStatus(200);
    }

    public function test_admin_role_route_rejects_plain_user()
    {
        Role::create(['name' => 'Admin']);
        $user = User::factory()->create();

        $response = $this->getJson('/api/v1/admin/products', [
            'Authorization' => 'Bearer ' . $this->tokenFor($user),
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_role_route_allows_admin_user()
    {
        Role::create(['name' => 'Admin']);
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $response = $this->getJson('/api/v1/admin/products', [
            'Authorization' => 'Bearer ' . $this->tokenFor($user),
        ]);

        $response->assertStatus(200);
    }

    public function test_superadmin_only_route_rejects_plain_admin()
    {
        Role::create(['name' => 'Admin']);
        Role::create(['name' => 'SuperAdmin']);
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $response = $this->getJson('/api/v1/admin/dashboard/statistics', [
            'Authorization' => 'Bearer ' . $this->tokenFor($user),
        ]);

        $response->assertStatus(403);
    }

    public function test_superadmin_only_route_allows_superadmin()
    {
        Role::create(['name' => 'SuperAdmin']);
        $user = User::factory()->create();
        $user->assignRole('SuperAdmin');

        $response = $this->getJson('/api/v1/admin/dashboard/statistics', [
            'Authorization' => 'Bearer ' . $this->tokenFor($user),
        ]);

        $response->assertStatus(200);
    }
}
