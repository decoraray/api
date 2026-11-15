<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): array
    {
        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_customer_user_response_does_not_include_permissions()
    {
        $user = User::factory()->create();

        $response = $this->getJson('/api/v1/user', $this->tokenFor($user));
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('permissions', $response->json());
    }

    public function test_superadmin_user_response_includes_wildcard_permissions()
    {
        $user = User::factory()->create();
        $user->assignRole('SuperAdmin');

        $response = $this->getJson('/api/v1/user', $this->tokenFor($user));
        $response->assertStatus(200);
        $this->assertEquals(['*'], $response->json('permissions'));
    }

    public function test_sales_user_response_includes_permission_names()
    {
        $user = User::factory()->create();
        $user->assignRole('Sales');

        $response = $this->getJson('/api/v1/user', $this->tokenFor($user));
        $response->assertStatus(200);
        $this->assertContains('orders.update-status', $response->json('permissions'));
        $this->assertNotContains('audit.view', $response->json('permissions'));
    }

    public function test_technic_user_response_includes_only_assigned_permissions()
    {
        $user = User::factory()->create();
        $user->assignRole('Technic');

        $response = $this->getJson('/api/v1/user', $this->tokenFor($user));
        $response->assertStatus(200);
        $this->assertContains('catalog.view', $response->json('permissions'));
        $this->assertNotContains('prices.view', $response->json('permissions'));
    }
}
