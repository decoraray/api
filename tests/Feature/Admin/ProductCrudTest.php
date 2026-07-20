<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminHeader(): array
    {
        Role::create(['name' => 'SuperAdmin']);
        $user = User::factory()->create();
        $user->assignRole('SuperAdmin');

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_index_lists_products()
    {
        Product::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/admin/products', $this->superAdminHeader());

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_show_returns_single_product()
    {
        $product = Product::factory()->create();

        $response = $this->getJson("/api/v1/admin/products/{$product->id}", $this->superAdminHeader());

        $response->assertStatus(200);
    }

    public function test_store_creates_product()
    {
        $category = Category::factory()->create();
        $header = $this->superAdminHeader();

        $response = $this->postJson('/api/v1/admin/products', [
            'title' => 'Test Product',
            'slug' => 'test-product',
            'description' => 'A description',
            'categories' => [$category->id],
        ], $header);

        $response->assertStatus(201);
        $this->assertDatabaseHas('products', ['slug' => 'test-product']);
    }

    public function test_store_validates_required_fields()
    {
        $response = $this->postJson('/api/v1/admin/products', [], $this->superAdminHeader());

        $response->assertStatus(422);
    }

    public function test_update_modifies_product()
    {
        $product = Product::factory()->create(['title' => 'Old Title']);
        $header = $this->superAdminHeader();

        $response = $this->putJson("/api/v1/admin/products/{$product->id}", [
            'title' => 'New Title',
        ], $header);

        $response->assertStatus(200);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'title' => 'New Title']);
    }

    public function test_destroy_deletes_product()
    {
        $product = Product::factory()->create();
        $header = $this->superAdminHeader();

        $response = $this->deleteJson("/api/v1/admin/products/{$product->id}", [], $header);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }
}
