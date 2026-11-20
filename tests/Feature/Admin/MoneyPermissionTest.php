<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(string $role): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_order_list_hides_money_from_technic_and_shows_for_sales()
    {
        Order::factory()->create();

        $technicResponse = $this->getJson('/api/v1/admin/orders', $this->tokenFor('Technic'));
        $technicResponse->assertStatus(200);
        $this->assertArrayNotHasKey('total', $technicResponse->json('data')[0] ?? []);

        $salesResponse = $this->getJson('/api/v1/admin/orders', $this->tokenFor('Sales'));
        $salesResponse->assertStatus(200);
        $this->assertArrayHasKey('total', $salesResponse->json('data')[0] ?? []);
    }

    public function test_order_detail_hides_money_from_technic()
    {
        $order = Order::factory()->create();
        $technicResponse = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->tokenFor('Technic'));
        $technicResponse->assertStatus(200);

        $json = $technicResponse->json('data');
        $this->assertArrayNotHasKey('shipping_price', $json);
        $this->assertArrayNotHasKey('items_total', $json);
        $this->assertArrayNotHasKey('total', $json);
        $this->assertArrayNotHasKey('payableAmount', $json);
        $this->assertArrayNotHasKey('discount_amount', $json);
        $this->assertArrayNotHasKey('payments', $json);
        // paid / remaining and the voided-payment amounts travel with payments: hidden too
        $this->assertArrayNotHasKey('paid', $json);
        $this->assertArrayNotHasKey('remaining', $json);
        $this->assertArrayHasKey('items', $json);
        $this->assertArrayNotHasKey('price', $json['items'][0] ?? []);
    }

    public function test_order_detail_shows_money_for_sales()
    {
        $order = Order::factory()->create();
        $salesResponse = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->tokenFor('Sales'));
        $salesResponse->assertStatus(200);

        $json = $salesResponse->json('data');
        $this->assertArrayHasKey('total', $json);
        $this->assertArrayHasKey('payments', $json);
    }

    public function test_product_list_hides_prices_from_technic_and_shows_for_sales()
    {
        Product::factory()->create();

        $technicResponse = $this->getJson('/api/v1/admin/products', $this->tokenFor('Technic'));
        $technicResponse->assertStatus(200);
        $this->assertArrayNotHasKey('price', $technicResponse->json('data')[0] ?? []);
        $this->assertArrayNotHasKey('old_price', $technicResponse->json('data')[0] ?? []);
        $this->assertArrayNotHasKey('discount', $technicResponse->json('data')[0] ?? []);

        $salesResponse = $this->getJson('/api/v1/admin/products', $this->tokenFor('Sales'));
        $salesResponse->assertStatus(200);
        $this->assertArrayHasKey('price', $salesResponse->json('data')[0] ?? []);
    }

    public function test_variation_detail_hides_prices_from_technic_and_shows_for_sales()
    {
        $variation = Variation::factory()->create();

        $technicResponse = $this->getJson("/api/v1/admin/variations/{$variation->id}", $this->tokenFor('Technic'));
        $technicResponse->assertStatus(200);
        $this->assertArrayNotHasKey('price', $technicResponse->json('data') ?? []);
        $this->assertArrayNotHasKey('old_price', $technicResponse->json('data') ?? []);

        $salesResponse = $this->getJson("/api/v1/admin/variations/{$variation->id}", $this->tokenFor('Sales'));
        $salesResponse->assertStatus(200);
        $this->assertArrayHasKey('price', $salesResponse->json('data') ?? []);
    }
}
