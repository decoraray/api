<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Image;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_images_are_returned_as_flat_url_strings_not_wrapped_arrays()
    {
        // NOTE: regression test. ImageShowResource::toArray() used to return a bare
        // string (Image::getImagePathAttribute()), but running it through
        // ImageShowResource::collection() sent each item through JsonResource::resolve(),
        // which (array)-casts non-array toArray() returns -- turning "https://...jpg"
        // into ["https://...jpg"]. That array-of-one then reached the frontend's
        // <NuxtImg :src="product.images[0]">, which requires a plain string and threw,
        // crashing SSR and cascading into a Vue Suspense unmount error client-side.
        // Fixed by plucking imagePath directly instead of routing through a JsonResource.
        $product = Product::factory()->create();
        $product->categories()->attach(Category::factory()->create());
        Image::factory()->count(2)->create(['product_id' => $product->id]);

        $response = $this->getJson("/api/v1/products/{$product->slug}");

        $response->assertStatus(200);
        $images = $response->json('images');

        $this->assertCount(2, $images);
        foreach ($images as $image) {
            $this->assertIsString($image);
        }
    }
}
