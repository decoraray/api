<?php

namespace Tests\Feature\Production;

use App\Models\BomLine;
use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\Variation;
use App\Services\Production\DesignService;
use Illuminate\Validation\ValidationException;

class DesignServiceTest extends CuttingTestCase
{
    private function designs(): DesignService
    {
        return app(DesignService::class);
    }

    public function test_first_design_is_default_and_names_are_unique()
    {
        $product = Product::factory()->create();
        $first = $this->designs()->create($product, ['name' => '۲۲۰ سانتی'], null);
        $second = $this->designs()->create($product, ['name' => '۱۸۰ سانتی'], null);

        $this->assertTrue($first->is_default);
        $this->assertFalse($second->is_default);

        $this->expectException(ValidationException::class);
        $this->designs()->create($product, ['name' => '۱۸۰ سانتی'], null);
    }

    public function test_copy_is_independent()
    {
        $product = $this->damtv220();
        $source = $this->design($product);
        $sheet = $this->stock(0);
        BomLine::create(['design_id' => $source->id, 'stock_item_id' => $sheet->id, 'quantity' => 0.5]);

        $copy = $this->designs()->create($product, ['name' => '۱۸۰ سانتی', 'copy_from_design_id' => $source->id], null);
        $this->assertSame(7, $copy->parts()->count());
        $this->assertSame(1, $copy->bomLines()->count());

        $copy->parts()->first()->update(['length_mm' => 1800]);
        $this->assertSame(2000, $source->parts()->first()->length_mm);

        $other = Product::factory()->create();
        $this->expectException(ValidationException::class);
        $this->designs()->create($other, ['name' => 'x', 'copy_from_design_id' => $source->id], null);
    }

    public function test_one_product_default_and_one_default_per_variation()
    {
        $variation = Variation::factory()->create();
        $product = $variation->product;
        $a = $this->designs()->create($product, ['name' => 'الف'], null);
        $b = $this->designs()->create($product, ['name' => 'ب'], null);

        $this->designs()->update($b, ['is_default' => true]);
        $this->assertSame([false, true], [$a->fresh()->is_default, $b->fresh()->is_default]);

        $this->designs()->update($a, ['variation_id' => $variation->id]);
        $this->designs()->update($b, ['variation_id' => $variation->id]);
        $this->assertNull($a->fresh()->variation_id);
        $this->assertSame($variation->id, $b->fresh()->variation_id);

        $foreign = Variation::factory()->create();
        $this->expectException(ValidationException::class);
        $this->designs()->update($a, ['variation_id' => $foreign->id]);
    }

    public function test_deactivating_clears_defaults()
    {
        $variation = Variation::factory()->create();
        $design = $this->designs()->create($variation->product, ['name' => 'الف', 'is_default' => true, 'variation_id' => $variation->id], null);

        $this->designs()->update($design, ['active' => false]);

        $design->refresh();
        $this->assertSame([false, false, null], [$design->active, $design->is_default, $design->variation_id]);

        $this->expectException(ValidationException::class);
        $this->designs()->update($design, ['active' => false, 'is_default' => true]);
    }

    public function test_delete_refused_while_referenced()
    {
        $product = Product::factory()->create();
        $refs = [
            'item' => fn ($d) => Item::create(['title' => 'x', 'slug' => 'x', 'price' => 1, 'quantity' => 1, 'order_id' => Order::factory()->create()->id, 'design_id' => $d->id]),
            'quote line' => fn ($d) => QuoteLine::create(['quote_id' => Quote::create(['customer_name' => 'A', 'phone' => '09123456789', 'shipping_mode' => 'amount', 'shipping_amount' => 0, 'discount' => 0, 'deposit_percent' => 60])->id, 'position' => 1, 'description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'design_id' => $d->id]),
            'work order' => fn ($d) => $this->workOrderFor(null)->update(['design_id' => $d->id]),
        ];

        foreach ($refs as $label => $refer) {
            $design = ProductDesign::factory()->create(['product_id' => $product->id]);
            $refer($design);
            try {
                $this->designs()->delete($design);
                $this->fail("deleted a design used by a $label");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('غیرفعال', $e->errors()['design'][0]);
            }
            $this->assertNotNull($design->fresh(), $label);
        }

        $unused = ProductDesign::factory()->create(['product_id' => $product->id]);
        $this->designs()->delete($unused);
        $this->assertNull($unused->fresh());
    }
}
