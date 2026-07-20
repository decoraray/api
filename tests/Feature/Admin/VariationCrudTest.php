<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VariationCrudTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminHeader(): array
    {
        Role::create(['name' => 'SuperAdmin']);
        $user = User::factory()->create();
        $user->assignRole('SuperAdmin');

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_index_lists_variations()
    {
        Variation::factory()->count(2)->create();

        $response = $this->getJson('/api/v1/admin/variations', $this->superAdminHeader());

        $response->assertStatus(200);
    }

    public function test_show_returns_single_variation()
    {
        $variation = Variation::factory()->create();

        $response = $this->getJson("/api/v1/admin/variations/{$variation->id}", $this->superAdminHeader());

        $response->assertStatus(200);
    }

    public function test_store_via_the_modern_colors_array_path_succeeds()
    {
        $product = Product::factory()->create();

        $response = $this->postJson('/api/v1/admin/variations', [
            'product_id' => $product->id,
            'stock' => 10,
            'weight' => 500,
            'price' => 150000,
            'active' => true,
            'colors' => ['Red'],
        ], $this->superAdminHeader());

        $response->assertStatus(201);
        $this->assertDatabaseHas('variations', ['product_id' => $product->id, 'price' => 150000]);
    }

    public function test_store_succeeds_with_legacy_single_color_field()
    {
        $product = Product::factory()->create();

        $response = $this->postJson('/api/v1/admin/variations', [
            'product_id' => $product->id,
            'stock' => 10,
            'weight' => 500,
            'price' => 150000,
            'active' => true,
            'color' => 'Red',
        ], $this->superAdminHeader());

        $response->assertStatus(201);
        $this->assertDatabaseHas('variations', ['product_id' => $product->id, 'price' => 150000]);
    }

    public function test_update_modifies_variation()
    {
        $variation = Variation::factory()->create(['stock' => 5]);

        $response = $this->putJson("/api/v1/admin/variations/{$variation->id}", [
            'stock' => 20,
        ], $this->superAdminHeader());

        $response->assertStatus(200);
        $this->assertDatabaseHas('variations', ['id' => $variation->id, 'stock' => 20]);
    }

    public function test_destroy_deletes_variation()
    {
        $variation = Variation::factory()->create();

        $response = $this->deleteJson("/api/v1/admin/variations/{$variation->id}", [], $this->superAdminHeader());

        $response->assertStatus(204);
        $this->assertDatabaseMissing('variations', ['id' => $variation->id]);
    }
}
