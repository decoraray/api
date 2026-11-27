<?php

namespace Tests\Feature\Variations;

use App\Models\Material;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\StockItem;
use App\Models\Variation;
use App\Services\Production\CalculatedLines;
use Tests\Feature\Production\CuttingTestCase;

abstract class VariationTestCase extends CuttingTestCase
{
    /** A high-gloss sheet: dearer than the walnut body, with its own edge band. */
    protected function gloss(): StockItem
    {
        $sheet = StockItem::firstOrCreate(['code' => 'HIGLOSS16'], [
            'name' => 'هایگلاس ۱۶', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16,
            'length_mm' => 2800, 'width_mm' => 1220, 'waste_percent' => 20, 'active' => true,
        ]);
        $this->price($sheet, 15_000_000);

        return $sheet;
    }

    protected function price(StockItem $item, int $averageCost): StockItem
    {
        $item->forceFill(['average_cost' => $averageCost])->save();

        return $item->refresh();
    }

    protected function material(string $name, ?StockItem $body = null, ?StockItem $front = null): Material
    {
        $material = Material::create(['name' => $name, 'slug' => 'm-' . uniqid(), 'active' => true]);
        $material->forceFill(['body_sheet_item_id' => $body?->id, 'front_sheet_item_id' => $front?->id])->save();

        return $material;
    }

    /** A product whose default design is the DAMTV220 unit, with its BOM calculated from the parts. */
    protected function unit(?Product $product = null): ProductDesign
    {
        $this->price($this->body16(), 6_000_000);
        $this->price($this->back3(), 3_000_000);
        $product ??= Product::factory()->create(['title' => 'DAMTV220', 'active' => true]);
        $design = $this->damtv220On($this->design($product));
        app(CalculatedLines::class)->applyToDesign($design, false);

        return $design->refresh();
    }

    protected function variationOf(ProductDesign $design, ?Material $material = null, array $attributes = []): Variation
    {
        return Variation::factory()->create($attributes + [
            'product_id' => $design->product_id, 'material_id' => $material?->id, 'design_id' => $design->id,
            'price' => 7_000_000, 'active' => true,
        ]);
    }
}
