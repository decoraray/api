<?php

namespace Tests\Feature\Production;

use App\Models\BomLine;
use App\Models\Item;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\ProductPart;
use App\Models\StockItem;
use App\Models\Variation;
use App\Services\Production\DesignResolver;

class DesignModelTest extends ProductionTestCase
{
    public function test_relations()
    {
        $design = ProductDesign::factory()->create();
        ProductPart::factory()->create(['design_id' => $design->id, 'position' => 1, 'name' => 'دوم']);
        ProductPart::factory()->create(['design_id' => $design->id, 'position' => 0, 'name' => 'اول']);
        BomLine::create(['design_id' => $design->id, 'stock_item_id' => StockItem::factory()->create()->id, 'quantity' => 2]);

        $fresh = $design->fresh();
        $this->assertSame(['اول', 'دوم'], $fresh->parts->pluck('name')->all());
        $this->assertSame(1, $fresh->bomLines->count());
        $this->assertTrue($fresh->product->designs->contains($design));
        $this->assertFalse($fresh->product->one_off);

        $design->product->delete();
        $this->assertSame(0, ProductPart::count());
        $this->assertSame(0, BomLine::count());
    }

    public function test_resolver_order()
    {
        $resolve = fn (...$args) => app(DesignResolver::class)->forLine(...$args)?->name;
        $variation = Variation::factory()->create();
        $productId = $variation->product_id;

        $this->assertNull($resolve(null, $productId, $variation->id));
        ProductDesign::factory()->create(['product_id' => $productId, 'name' => 'پیش‌فرض', 'is_default' => true]);
        $this->assertSame('پیش‌فرض', $resolve(null, $productId, $variation->id));
        $variation->update(['design_id' => ProductDesign::factory()->create(['product_id' => $productId, 'name' => 'تنوع'])->id]);
        $this->assertSame('تنوع', $resolve(null, $productId, $variation->id));

        $chosen = ProductDesign::factory()->create(['product_id' => $productId, 'name' => 'انتخابی', 'active' => false]);
        $this->assertSame('انتخابی', $resolve($chosen->id, $productId, $variation->id), 'a deactivated chosen design is still used');

        $this->assertNull($resolve(null, null, null));
        $this->assertNull(app(DesignResolver::class)->forItem(null));
        $this->assertSame('انتخابی', app(DesignResolver::class)->forItem(new Item(['design_id' => $chosen->id]))?->name);
        $this->assertNull($resolve(null, Product::factory()->create()->id, null));
    }
}
