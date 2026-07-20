<?php

namespace Tests\Feature\Shop;

use App\Models\Address;
use App\Models\Shipping;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_cash_checkout_happy_path_creates_pending_order_and_clears_cart()
    {
        // City::getShippingAttribute() falls back to Shipping::where('id', 1) when no
        // city-shipping pivot rows exist, so a single Shipping with id 1 is sufficient.
        Shipping::factory()->create(['id' => 1]);

        $user = User::factory()->create();
        $address = Address::factory()->create(['user_id' => $user->id]);
        $variation = Variation::factory()->create(['price' => 100000]);

        $this->postJson('/api/v1/add', ['variation' => $variation->id], $this->authHeader($user))
            ->assertStatus(200);

        $response = $this->postJson('/api/v1/checkout', [
            'address_id' => $address->uuid,
            'payment_method' => 'cash',
        ], $this->authHeader($user));

        $response->assertStatus(201)
            ->assertJsonPath('url', '');

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'status' => 'درانتظار بررسی',
        ]);
        $this->assertDatabaseHas('items', ['product_id' => $variation->product_id]);

        $user->cart->refresh();
        $this->assertTrue($user->cart->isEmpty());
    }

    public function test_checkout_rejects_empty_cart()
    {
        Shipping::factory()->create(['id' => 1]);

        $user = User::factory()->create();
        $address = Address::factory()->create(['user_id' => $user->id]);

        $response = $this->postJson('/api/v1/checkout', [
            'address_id' => $address->uuid,
            'payment_method' => 'cash',
        ], $this->authHeader($user));

        $response->assertStatus(400)
            ->assertJsonPath('message', 'cart is empty');
    }

    public function test_online_checkout_creates_payment_and_returns_gateway_url()
    {
        Shipping::factory()->create(['id' => 1]);
        Http::fake([
            '*/v3/pay' => Http::response(['paymentCode' => 'abc123'], 200),
        ]);

        $user = User::factory()->create();
        $address = Address::factory()->create(['user_id' => $user->id]);
        $variation = Variation::factory()->create(['price' => 100000]);

        $this->postJson('/api/v1/add', ['variation' => $variation->id], $this->authHeader($user));

        $response = $this->postJson('/api/v1/checkout', [
            'address_id' => $address->uuid,
            'payment_method' => 'online',
            'gateway' => 'payping',
        ], $this->authHeader($user));

        $response->assertStatus(201)
            ->assertJsonStructure(['url']);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'gateway' => 'payping',
            'status' => 'pending',
        ]);
    }

    public function test_checkout_requires_authentication()
    {
        $response = $this->postJson('/api/v1/checkout', [
            'address_id' => 'whatever',
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(401);
    }
}
