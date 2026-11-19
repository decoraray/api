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

    public function test_checkout_copies_the_line_description_to_the_order_item()
    {
        Shipping::factory()->create(['id' => 1]);

        $user = User::factory()->create();
        $address = Address::factory()->create(['user_id' => $user->id]);
        $variation = Variation::factory()->create(['price' => 100000]);

        $this->postJson('/api/v1/add', [
            'variation' => $variation->id,
            'description' => 'رنگ: ونگه',
        ], $this->authHeader($user))->assertStatus(200);

        $this->postJson('/api/v1/checkout', [
            'address_id' => $address->uuid,
            'payment_method' => 'cash',
        ], $this->authHeader($user))->assertStatus(201);

        $this->assertDatabaseHas('items', [
            'variation_id' => $variation->id,
            'description' => 'رنگ: ونگه',
        ]);
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

    public function test_online_checkout_sends_only_documented_payping_fields()
    {
        Shipping::factory()->create(['id' => 1]);
        Http::fake([
            '*/v3/pay' => Http::response(['paymentCode' => 'abc123'], 200),
        ]);

        $user = User::factory()->create(['phone' => '09120000000']);
        $address = Address::factory()->create(['user_id' => $user->id]);
        $variation = Variation::factory()->create(['price' => 100000]);

        $this->postJson('/api/v1/add', ['variation' => $variation->id], $this->authHeader($user));

        $this->postJson('/api/v1/checkout', [
            'address_id' => $address->uuid,
            'payment_method' => 'online',
            'gateway' => 'payping',
        ], $this->authHeader($user))->assertStatus(201);

        Http::assertSent(function ($request) {
            $allowed = ['amount', 'returnUrl', 'payerIdentity', 'payerName', 'description', 'clientRefId', 'isReversible', 'IsBlocked'];

            return str_ends_with($request->url(), '/v3/pay')
                && array_diff(array_keys($request->data()), $allowed) === []
                && $request['payerIdentity'] === '09120000000';
        });
    }

    public function test_online_checkout_returns_502_and_fails_payment_when_payping_rejects()
    {
        Shipping::factory()->create(['id' => 1]);
        Http::fake([
            '*/v3/pay' => Http::response([
                'type' => 'https://datatracker.ietf.org/doc/html/rfc7231#section-6.5.1',
                'title' => 'PolicyException',
                'status' => 400,
                'detail' => 'policy rejected',
                'paypingTraceId' => 'TRACE-1',
            ], 400),
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

        $response->assertStatus(502)->assertJsonStructure(['message', 'order']);

        $payment = \App\Models\Payment::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('failed', $payment->status);
        $this->assertStringContainsString('PolicyException', $payment->error);
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
