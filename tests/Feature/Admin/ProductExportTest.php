<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductExportTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminHeader(): array
    {
        Role::create(['name' => 'SuperAdmin']);
        $user = User::factory()->create();
        $user->assignRole('SuperAdmin');

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_export_returns_csv_with_expected_headers_and_columns()
    {
        $product = Product::factory()->create(['title' => 'Test Product']);
        Variation::factory()->create(['product_id' => $product->id, 'price' => 250000]);

        $response = $this->get('/api/v1/admin/products/export', $this->superAdminHeader());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition');

        $content = $response->streamedContent();

        $this->assertStringContainsString('شناسه محصول', $content);
        $this->assertStringContainsString('Test Product', $content);
        $this->assertStringContainsString('250000', $content);
    }

    public function test_export_requires_authentication()
    {
        $response = $this->get('/api/v1/admin/products/export');

        $response->assertStatus(401);
    }
}
