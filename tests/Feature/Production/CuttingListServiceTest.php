<?php

namespace Tests\Feature\Production;

use App\Enums\CuttingOp;
use App\Models\Product;
use App\Models\ProductPart;
use App\Models\Variation;
use App\Services\Production\DesignResolver;
use Illuminate\Support\Facades\Schema;

class CuttingListServiceTest extends CuttingTestCase
{
    public function test_schema()
    {
        $this->assertTrue(Schema::hasColumns('product_parts', [
            'design_id', 'position', 'name', 'length_mm', 'width_mm', 'count',
            'stock_item_id', 'edge_length', 'edge_width', 'grain_locked', 'ops', 'note',
        ]));
        $this->assertTrue(Schema::hasColumns('stock_items', ['waste_percent', 'default_edge_band_id']));
        $this->assertTrue(Schema::hasColumns('bom_lines', ['calculated_quantity']));
        $this->assertTrue(Schema::hasIndex('product_parts', ['design_id', 'position']));
        $this->assertFalse(Schema::hasColumn('product_parts', 'product_id'));
    }

    public function test_cutting_op_options_and_ops_cast_round_trip()
    {
        $this->assertSame(['cnc', 'edge_fold', 'groove', 'drill'], array_column(CuttingOp::options(), 'id'));
        $this->assertSame('لبه خم', CuttingOp::EDGE_FOLD->label());

        $part = ProductPart::factory()->create(['ops' => ['edge_fold', 'cnc']]);
        $ops = $part->fresh()->ops;
        $this->assertSame([CuttingOp::EDGE_FOLD, CuttingOp::CNC], $ops->all());
    }

    public function test_damtv220_summary()
    {
        $product = $this->damtv220();
        $parts = $this->design($product)->parts()->get();
        $this->assertSame(['صفحه رویی', 'کف', 'درب ها', 'طبقه', 'بغل ها', 'وادارها', 'پشت بند'], $parts->pluck('name')->all());

        [$body, $back] = $this->cutting()->summary($parts);

        $this->assertSame(['MDF-16-WAL', 20, 3660, 1830], [$body['sheet']['code'], $body['sheet']['waste_percent'], $body['sheet']['sheet_length_mm'], $body['sheet']['sheet_width_mm']]);
        $this->assertNull($body['edge_band']);
        $this->assertSame(13, $body['pieces']);
        $this->assertSame(2.761, $body['area_m2']);
        $this->assertSame(0.5, $body['sheets']);
        // doors: 4 × 2 × (283 + 30) mm = 2.504 m, rounded up to 2.6
        $this->assertSame(2.6, $body['edge_m']);

        $this->assertSame(0.409, $back['area_m2']);
        $this->assertSame(0.07, $back['sheets']);
        $this->assertSame(0.0, $back['edge_m']);
    }

    public function test_multiplier_scales_counts_and_area()
    {
        $parts = $this->design($this->damtv220())->parts()->get();

        [$body] = $this->cutting()->summary($parts, 2);

        $this->assertSame(26, $body['pieces']);
        $this->assertSame(5.522, $body['area_m2']);
        $this->assertSame(0.99, $body['sheets']);
        $this->assertSame(5.1, $body['edge_m']);
    }

    public function test_variation_default_design_overrides_and_others_fall_back()
    {
        $walnut = Variation::factory()->create();
        $other = Variation::factory()->create(['product_id' => $walnut->product_id]);
        ProductPart::factory()->create(['design_id' => $this->design($walnut->product)->id, 'name' => 'مشترک']);
        ProductPart::factory()->create(['design_id' => $this->design($walnut->product, $walnut)->id, 'name' => 'گردویی']);
        $resolve = fn ($productId, $variationId) => app(DesignResolver::class)->forLine(null, $productId, $variationId);

        $this->assertSame(['گردویی'], $resolve($walnut->product_id, $walnut->id)->parts->pluck('name')->all());
        $this->assertSame(['مشترک'], $resolve($walnut->product_id, $other->id)->parts->pluck('name')->all());
        $this->assertNull($resolve(null, null));
        $this->assertNull($resolve(Product::factory()->create()->id, null));
    }

    public function test_fit_warnings()
    {
        $part = fn ($l, $w) => new ProductPart(['name' => 'قطعه', 'length_mm' => $l, 'width_mm' => $w, 'count' => 1, 'stock_item_id' => $this->body16()->id]);
        $parts = collect([$part(2000, 400), $part(400, 2000), $part(2100, 400), $part(1000, 450)]);

        foreach (['200*40*40', '200×40×40', '۲۰۰ x ۴۰ x ۴۰', '40*200*40'] as $dimensions) {
            $warnings = $this->cutting()->fitWarnings($dimensions, $parts);
            // fits as written and rotated; too long; too wide
            $this->assertSame([2, 3], array_column($warnings, 'index'), $dimensions);
        }
        $this->assertStringContainsString('210 × 40', $this->cutting()->fitWarnings('200*40*40', $parts)[0]['message']);

        foreach ([null, '', 'طول دلخواه', '200*40'] as $dimensions) {
            $this->assertSame([], $this->cutting()->fitWarnings($dimensions, $parts));
        }
    }

    public function test_calculated_lines_for_damtv220_take_the_band_from_the_sheet()
    {
        $parts = $this->design($this->damtv220())->parts()->get();
        $this->edgeBand();

        $lines = $this->cutting()->calculatedLines($parts);

        $this->assertSame([['MDF-16-WAL', 0.5], ['EB-WAL-2', 2.6], ['HDF-3-WH', 0.07]],
            array_map(fn ($l) => [$l['stock_item']->code, $l['quantity']], array_values($lines)));
    }

    public function test_a_sheet_without_size_is_taken_as_3660_by_1830_and_its_own_size_wins()
    {
        $design = $this->design(Product::factory()->create());
        $small = $this->stock(0, ['kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16, 'length_mm' => 2440, 'width_mm' => 1220, 'waste_percent' => 20]);
        $plain = $this->stock(0, ['kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16, 'waste_percent' => 20]);
        foreach ([$small, $plain] as $sheet) {
            foreach ($this->damtv220Parts() as $i => $p) {
                if ($p['stock_item_id'] === $this->body16()->id) {
                    ProductPart::create(['stock_item_id' => $sheet->id, 'design_id' => $design->id, 'position' => $i] + $p);
                }
            }
        }

        $lines = $this->cutting()->calculatedLines($design->parts()->get());

        // 2.761102 m² × 1.2 ÷ (2.44 × 1.22) = 1.113 → 1.12; on the standard size 0.50
        $this->assertSame([$small->id => 1.12, $plain->id => 0.5], array_map(fn ($l) => $l['quantity'], $lines));
    }

    public function test_lines_of_parts_sharing_a_sheet_are_summed()
    {
        $design = $this->design(Product::factory()->create());
        $band = $this->edgeBand();
        ProductPart::factory()->create(['design_id' => $design->id, 'stock_item_id' => $this->body16()->id, 'length_mm' => 970, 'width_mm' => 400, 'count' => 1, 'edge_length' => 1]);
        ProductPart::factory()->create(['design_id' => $design->id, 'stock_item_id' => $this->body16()->id, 'length_mm' => 470, 'width_mm' => 400, 'count' => 2, 'edge_length' => 2, 'edge_width' => 2]);

        $lines = $this->cutting()->calculatedLines($design->parts()->get());

        // 0.764 m² × 1.2 ÷ 6.6978 = 0.1369 → 0.14; band 1.0 m + 2 × (2 × 0.5 + 2 × 0.43) = 4.72 → 4.8
        $this->assertSame([[$this->body16()->id, 0.14], [$band->id, 4.8]], array_map(fn ($l) => [$l['stock_item']->id, $l['quantity']], array_values($lines)));
    }
}
