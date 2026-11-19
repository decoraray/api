<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A one-off product is never shown on the storefront, even if staff make it active and give it a variation. */
class OneOffProductVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $title, bool $oneOff, Category $category): Product
    {
        $product = Product::factory()->create(['title' => $title, 'slug' => $oneOff ? 'one-off-wo-1-1' : 'desk', 'active' => true]);
        $product->forceFill(['one_off' => $oneOff])->save();
        Variation::factory()->create(['product_id' => $product->id, 'active' => true]);
        $product->categories()->attach($category);

        return $product;
    }

    private function header(string $permission): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    public function test_storefront_never_returns_one_off_products()
    {
        $category = Category::factory()->create(['slug' => 'tv']);
        $normal = $this->product('میز معمولی', false, $category);
        $oneOff = $this->product('جلومبلی خانم احمدی', true, $category);
        $this->assertFalse($oneOff->shouldBeSearchable());
        $this->assertTrue($normal->shouldBeSearchable());

        $this->getJson("/api/v1/products/{$oneOff->id}")->assertStatus(404);
        $this->getJson("/api/v1/products/{$oneOff->slug}")->assertStatus(404);
        $this->getJson("/api/v1/categories/tv/{$oneOff->slug}")->assertStatus(404);
        $this->getJson("/api/v1/products/{$normal->slug}")->assertStatus(200);

        $lists = [
            '/api/v1/products',
            '/api/v1/products?keyword=' . urlencode('جلومبلی'),
            '/api/v1/categories/tv',
            '/api/v1/initials',
            '/api/v1/torob',
            '/api/v1/all-products',
        ];
        foreach ($lists as $url) {
            $body = json_encode($this->getJson($url)->assertStatus(200)->json(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('one-off-wo-1-1', $body, $url);
            $this->assertStringNotContainsString('جلومبلی خانم احمدی', $body, $url);
        }
        $this->assertStringContainsString('desk', json_encode($this->getJson('/api/v1/all-products')->json()));
    }

    public function test_admin_list_hides_one_offs_unless_asked_and_the_picker_finds_them()
    {
        $category = Category::factory()->create();
        $this->product('میز معمولی', false, $category);
        $oneOff = Product::factory()->create(['title' => 'جلومبلی خانم احمدی', 'active' => false]);
        $oneOff->forceFill(['one_off' => true])->save();
        ProductDesign::factory()->create(['product_id' => $oneOff->id, 'name' => 'استاندارد', 'is_default' => true]);
        $catalog = $this->header('catalog.view');

        $titles = fn ($url, $headers) => collect($this->getJson($url, $headers)->assertStatus(200)->json('data'))->pluck('title')->all();
        $this->assertSame(['میز معمولی'], $titles('/api/v1/admin/products', $catalog));
        $this->assertSame(['جلومبلی خانم احمدی'], $titles('/api/v1/admin/products?one_off=1', $catalog));

        foreach (['orders.create', 'quotes.manage'] as $permission) {
            $this->getJson('/api/v1/admin/products/one-off?search=' . urlencode('احمدی'), $this->header($permission))
                ->assertStatus(200)
                ->assertJsonPath('data.0.title', 'جلومبلی خانم احمدی')
                ->assertJsonPath('data.0.designs.0.name', 'استاندارد');
        }
        $this->getJson('/api/v1/admin/products/one-off', $this->header('catalog.view'))->assertStatus(403);
    }
}
