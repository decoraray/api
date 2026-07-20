<?php

namespace Tests\Feature\Shop;

use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_variation_to_guest_cart()
    {
        $variation = Variation::factory()->create();

        $response = $this->postJson('/api/v1/add', [
            'variation' => $variation->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('cart.isEmpty', false);
    }

    public function test_add_twice_increments_quantity()
    {
        // A guest cart is identified by `uuid`; each request must carry the uuid returned
        // by the previous response, exactly like the real frontend does, or `Cart::getOrCreate`
        // spins up a brand-new guest cart every time.
        $variation = Variation::factory()->create();

        $first = $this->postJson('/api/v1/add', ['variation' => $variation->id]);
        $uuid = $first->json('cart_uuid');

        $response = $this->postJson('/api/v1/add', [
            'variation' => $variation->id,
            'uuid' => $uuid,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('cart.count', 2);
    }

    public function test_remove_variation_decrements_then_detaches()
    {
        $variation = Variation::factory()->create();

        $first = $this->postJson('/api/v1/add', ['variation' => $variation->id]);
        $uuid = $first->json('cart_uuid');
        $this->postJson('/api/v1/add', ['variation' => $variation->id, 'uuid' => $uuid]);

        $this->postJson('/api/v1/remove', ['variation' => $variation->id, 'uuid' => $uuid])
            ->assertStatus(200)
            ->assertJsonPath('cart.count', 1);

        $response = $this->postJson('/api/v1/remove', ['variation' => $variation->id, 'uuid' => $uuid]);

        $response->assertStatus(200)
            ->assertJsonPath('cart.isEmpty', true);
    }

    public function test_authenticated_users_cart_is_scoped_to_their_account()
    {
        $user = User::factory()->create();
        $token = AuthService::createToken($user);
        $variation = Variation::factory()->create();

        $this->postJson('/api/v1/add', ['variation' => $variation->id], [
            'Authorization' => "Bearer $token",
        ])->assertStatus(200);

        $this->assertDatabaseHas('carts', ['user_id' => $user->id]);
    }
}
