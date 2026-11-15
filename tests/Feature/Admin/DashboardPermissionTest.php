<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(string $role): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_superadmin_dashboard_includes_revenue()
    {
        Order::factory()->create();

        $response = $this->getJson('/api/v1/admin/dashboard/statistics', $this->tokenFor('SuperAdmin'));
        $response->assertStatus(200);
        $response->assertJsonPath('revenue.total', fn ($value) => $value !== null);
        $response->assertJsonPath('recent_orders.0.total_price', fn ($value) => $value !== null);
    }

    public function test_sales_dashboard_excludes_revenue_but_includes_recent_order_totals()
    {
        Order::factory()->create();

        $response = $this->getJson('/api/v1/admin/dashboard/statistics', $this->tokenFor('Sales'));
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('revenue', $response->json());
        $response->assertJsonPath('recent_orders.0.total_price', fn ($value) => $value !== null);
    }

    public function test_technic_dashboard_excludes_revenue_and_recent_order_totals()
    {
        Order::factory()->create();

        $response = $this->getJson('/api/v1/admin/dashboard/statistics', $this->tokenFor('Technic'));
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('revenue', $response->json());
        $this->assertArrayNotHasKey('total_price', $response->json('recent_orders.0') ?? []);
    }
}
