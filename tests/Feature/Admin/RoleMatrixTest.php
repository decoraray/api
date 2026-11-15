<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(string $role): array
    {
        $user = User::factory()->create();
        if ($role !== 'customer') {
            $user->assignRole($role);
        }

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_technic_can_view_catalog_orders_and_dashboard_but_not_write_or_export()
    {
        $headers = $this->tokenFor('Technic');
        $product = Product::factory()->create();
        $order = Order::factory()->create();

        // allowed
        $this->getJson('/api/v1/admin/products', $headers)->assertStatus(200);
        $this->getJson("/api/v1/admin/products/{$product->id}", $headers)->assertStatus(200);
        $this->getJson('/api/v1/admin/orders', $headers)->assertStatus(200);
        $this->getJson("/api/v1/admin/orders/uuid/{$order->uuid}", $headers)->assertStatus(200);
        $this->patchJson("/api/v1/admin/orders/{$order->uuid}", ['status' => 'CNC'], $headers)->assertStatus(200);
        $this->getJson('/api/v1/admin/dashboard/statistics', $headers)->assertStatus(200);

        // denied
        $this->postJson('/api/v1/admin/products', ['title' => 'x', 'slug' => 'x'], $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/products/export', $headers)->assertStatus(403);
        $this->getJson("/api/v1/admin/orders/pdf/{$order->uuid}", $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/users', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/staff', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/audit-log', $headers)->assertStatus(403);
    }

    public function test_sales_can_view_orders_customers_dashboard_and_invoice_but_not_catalog_write_or_staff_audit()
    {
        $headers = $this->tokenFor('Sales');
        $product = Product::factory()->create();
        $order = Order::factory()->create();
        $user = User::factory()->create();

        // allowed
        $this->patchJson("/api/v1/admin/orders/{$order->uuid}", ['status' => 'CONFIRMED'], $headers)->assertStatus(200);
        $this->getJson("/api/v1/admin/orders/pdf/{$order->uuid}", $headers)->assertStatus(200);
        $this->getJson('/api/v1/admin/users', $headers)->assertStatus(200);
        $this->getJson('/api/v1/admin/dashboard/statistics', $headers)->assertStatus(200);

        // denied
        $this->postJson('/api/v1/admin/products', ['title' => 'x', 'slug' => 'x'], $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/staff', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/audit-log', $headers)->assertStatus(403);
    }

    public function test_legacy_admin_can_list_and_export_but_not_update_orders_or_dashboard()
    {
        $headers = $this->tokenFor('Admin');
        $product = Product::factory()->create();
        $order = Order::factory()->create();

        // allowed
        $this->getJson('/api/v1/admin/products', $headers)->assertStatus(200);
        $this->getJson('/api/v1/admin/products/export', $headers)->assertStatus(200);
        $this->getJson('/api/v1/admin/orders', $headers)->assertStatus(200);

        // denied
        $this->patchJson("/api/v1/admin/orders/{$order->uuid}", ['status' => 'CONFIRMED'], $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/dashboard/statistics', $headers)->assertStatus(403);
    }

    public function test_customer_is_denied_all_admin_routes()
    {
        $headers = $this->tokenFor('customer');
        $product = Product::factory()->create();
        $order = Order::factory()->create();

        $this->getJson('/api/v1/admin/products', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/products/export', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/orders', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/users', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/dashboard/statistics', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/staff', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/audit-log', $headers)->assertStatus(403);
    }
}
