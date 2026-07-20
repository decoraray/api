<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderCrudTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminHeader(): array
    {
        Role::create(['name' => 'SuperAdmin']);
        $user = User::factory()->create();
        $user->assignRole('SuperAdmin');

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_index_lists_orders()
    {
        Order::factory()->count(2)->create();

        $response = $this->getJson('/api/v1/admin/orders', $this->superAdminHeader());

        $response->assertStatus(200);
    }

    public function test_show_returns_single_order()
    {
        $order = Order::factory()->create();

        $response = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->superAdminHeader());

        $response->assertStatus(200);
    }

    public function test_update_changes_order_status()
    {
        $order = Order::factory()->create();

        $response = $this->patchJson("/api/v1/admin/orders/{$order->uuid}", [
            'status' => 'CONFIRMED',
        ], $this->superAdminHeader());

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'تایید شده']);
    }
}
