<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Image;
use App\Models\Product;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductListQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private function seedProducts(int $count): void
    {
        $category = Category::factory()->create();

        Product::factory()->count($count)->create()->each(function (Product $product) use ($category) {
            $product->categories()->attach($category);
            Variation::factory()->create(['product_id' => $product->id, 'active' => 1]);
            $images = Image::factory()->count(2)->create(['product_id' => $product->id]);
            $product->update(['thumbnail_id' => $images->first()->id]);
        });
    }

    private function countQueries(string $url): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->getJson($url)->assertStatus(200);

        return $queries;
    }

    public function test_product_list_query_count_does_not_grow_with_page_size()
    {
        // Regression test: ProductListResource reads variations, deal, categories,
        // rating, images and thumbnail. Without eager loading every product in the
        // page fired ~6 queries of its own (~120 for a 20-product shop page).
        $this->seedProducts(3);
        $small = $this->countQueries('/api/v1/products?per_page=3&probe=small');

        $this->seedProducts(12);
        $large = $this->countQueries('/api/v1/products?per_page=15&probe=large');

        $this->assertSame($small, $large, "product list ran $small queries for 3 products but $large for 15");
    }

    public function test_thumbnail_is_the_chosen_image_or_the_newest_one()
    {
        $product = Product::factory()->create();
        $images = Image::factory()->count(3)->create(['product_id' => $product->id]);

        $this->assertSame($images->last()->id, $product->fresh()->thumbnail->id);

        $product->update(['thumbnail_id' => $images->first()->id]);
        $this->assertSame($images->first()->id, $product->fresh()->thumbnail->id);
    }
}
