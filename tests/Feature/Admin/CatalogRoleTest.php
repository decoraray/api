<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogRoleTest extends TestCase
{
    use RefreshDatabase;

    private function catalogHeader(): array
    {
        $user = User::factory()->create();
        $user->assignRole('Catalog');

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_catalog_role_has_exactly_its_grants()
    {
        $user = User::factory()->create();
        $user->assignRole('Catalog');

        $this->assertTrue($user->isStaff());
        $this->assertEqualsCanonicalizing(
            ['catalog.view', 'catalog.edit', 'prices.view', 'prices.wholesale', 'blog.manage'],
            $user->getAllPermissions()->pluck('name')->all()
        );
    }

    public function test_catalog_can_manage_products_and_categories_but_not_orders_or_staff()
    {
        $headers = $this->catalogHeader();
        $product = Product::factory()->create();
        $order = Order::factory()->create();

        // allowed
        $this->getJson('/api/v1/admin/products', $headers)->assertStatus(200);
        $this->getJson('/api/v1/admin/products/export', $headers)->assertStatus(200);
        $this->postJson('/api/v1/admin/categories', [], $headers)->assertStatus(422); // past the gate, fails validation
        $this->deleteJson("/api/v1/admin/products/{$product->id}", [], $headers)->assertSuccessful();
        $this->getJson('/api/v1/admin/posts', $headers)->assertStatus(200);

        // denied
        $this->getJson('/api/v1/admin/orders', $headers)->assertStatus(403);
        $this->getJson("/api/v1/admin/orders/uuid/{$order->uuid}", $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/users', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/staff', $headers)->assertStatus(403);
        $this->getJson('/api/v1/admin/audit-log', $headers)->assertStatus(403);
    }

    public function test_super_admin_can_assign_catalog_role()
    {
        $admin = User::factory()->create();
        $admin->assignRole('SuperAdmin');
        $staff = User::factory()->create();
        $staff->assignRole('Technic');

        $this->patchJson("/api/v1/admin/staff/{$staff->id}", ['role' => 'Catalog', 'password' => 'new-secret-1'],
            ['Authorization' => 'Bearer ' . AuthService::createToken($admin)])
            ->assertStatus(200);

        $this->assertSame(['Catalog'], $staff->fresh()->getRoleNames()->all());
    }
}
