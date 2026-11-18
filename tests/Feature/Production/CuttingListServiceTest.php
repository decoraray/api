<?php

namespace Tests\Feature\Production;

use App\Enums\CuttingOp;
use App\Models\PanelMaterial;
use App\Models\Product;
use App\Models\ProductPart;
use App\Models\Variation;
use Illuminate\Support\Facades\Schema;

class CuttingListServiceTest extends CuttingTestCase
{
    public function test_schema_and_seeded_panel_materials()
    {
        $this->assertTrue(Schema::hasColumns('panel_materials', ['name', 'thickness_mm', 'sheet_length_mm', 'sheet_width_mm', 'waste_percent', 'active']));
        $this->assertTrue(Schema::hasColumns('product_parts', [
            'product_id', 'variation_id', 'position', 'name', 'length_mm', 'width_mm', 'count',
            'panel_material_id', 'edge_length', 'edge_width', 'grain_locked', 'ops', 'note',
        ]));
        $this->assertTrue(Schema::hasIndex('product_parts', ['product_id', 'variation_id', 'position']));

        $this->assertSame([16.0, 20, 3660, 1830], [$this->body16()->thickness_mm, $this->body16()->waste_percent, $this->body16()->sheet_length_mm, $this->body16()->sheet_width_mm]);
        $this->assertSame([3.0, 10, 3660, 1830], [$this->back3()->thickness_mm, $this->back3()->waste_percent, $this->back3()->sheet_length_mm, $this->back3()->sheet_width_mm]);
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
        [$parts, $source] = $this->cutting()->resolve($product->id, null);
        $this->assertSame('product', $source);
        $this->assertSame(['صفحه رویی', 'کف', 'درب ها', 'طبقه', 'بغل ها', 'وادارها', 'پشت بند'], $parts->pluck('name')->all());

        [$body, $back] = $this->cutting()->summary($parts);

        $this->assertSame('بدنه ۱۶ میل', $body['panel_material']['name']);
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
        [$parts] = $this->cutting()->resolve($this->damtv220()->id, null);

        [$body] = $this->cutting()->summary($parts, 2);

        $this->assertSame(26, $body['pieces']);
        $this->assertSame(5.522, $body['area_m2']);
        $this->assertSame(0.99, $body['sheets']);
        $this->assertSame(5.1, $body['edge_m']);
    }

    public function test_variation_list_overrides_and_others_fall_back()
    {
        $walnut = Variation::factory()->create();
        $other = Variation::factory()->create(['product_id' => $walnut->product_id]);
        ProductPart::factory()->create(['product_id' => $walnut->product_id, 'name' => 'مشترک']);
        ProductPart::factory()->create(['product_id' => $walnut->product_id, 'variation_id' => $walnut->id, 'name' => 'گردویی']);

        [$own, $source] = $this->cutting()->resolve($walnut->product_id, $walnut->id);
        $this->assertSame(['variation', ['گردویی']], [$source, $own->pluck('name')->all()]);

        [$shared, $source] = $this->cutting()->resolve($walnut->product_id, $other->id);
        $this->assertSame(['product', ['مشترک']], [$source, $shared->pluck('name')->all()]);

        $this->assertSame('none', $this->cutting()->resolve(null, null)[1]);
        $this->assertSame('none', $this->cutting()->resolve(Product::factory()->create()->id, null)[1]);
    }

    public function test_fit_warnings()
    {
        $material = PanelMaterial::factory()->create();
        $part = fn ($l, $w) => new ProductPart(['name' => 'قطعه', 'length_mm' => $l, 'width_mm' => $w, 'count' => 1, 'panel_material_id' => $material->id]);
        $parts = collect([$part(2000, 400), $part(400, 2000), $part(2100, 400), $part(1000, 450)]);

        foreach (['200*40*40', '200×40×40', '۲۰۰ x ۴۰ x ۴۰', '40*200*40'] as $dimensions) {
            $warnings = $this->cutting()->fitWarnings(new Product(['dimensions' => $dimensions]), $parts);
            // fits as written and rotated; too long; too wide
            $this->assertSame([2, 3], array_column($warnings, 'index'), $dimensions);
        }
        $this->assertStringContainsString('210 × 40', $this->cutting()->fitWarnings(new Product(['dimensions' => '200*40*40']), $parts)[0]['message']);

        foreach ([null, '', 'طول دلخواه', '200*40'] as $dimensions) {
            $this->assertSame([], $this->cutting()->fitWarnings(new Product(['dimensions' => $dimensions]), $parts));
        }
    }

    public function test_bom_proposal_for_damtv220()
    {
        [$parts] = $this->cutting()->resolve($this->damtv220()->id, null);
        $walnut = $this->stock(0, ['code' => 'MDF-16-WAL', 'kind' => 'mdf_sheet', 'unit' => 'sheet']);
        $band = $this->stock(0, ['code' => 'EB-WAL-2', 'kind' => 'edge_band', 'unit' => 'meter']);
        $hdf = $this->stock(0, ['code' => 'HDF-3-WH', 'kind' => 'mdf_sheet', 'unit' => 'sheet']);

        $lines = $this->cutting()->proposeBomLines($parts, [
            $this->body16()->id => ['sheet' => $walnut, 'edge_band' => $band],
            $this->back3()->id => ['sheet' => $hdf, 'edge_band' => $band],
        ]);

        $this->assertSame([['MDF-16-WAL', 0.5], ['EB-WAL-2', 2.6], ['HDF-3-WH', 0.07]],
            array_map(fn ($l) => [$l['stock_item']->code, $l['quantity']], $lines));
    }

    public function test_bom_proposal_uses_the_sheet_items_own_size()
    {
        [$parts] = $this->cutting()->resolve($this->damtv220()->id, null);
        $small = $this->stock(0, ['kind' => 'mdf_sheet', 'unit' => 'sheet', 'length_mm' => 2440, 'width_mm' => 1220]);
        $hdf = $this->stock(0, ['kind' => 'mdf_sheet', 'unit' => 'sheet']);

        $lines = $this->cutting()->proposeBomLines($parts, [
            $this->body16()->id => ['sheet' => $small, 'edge_band' => null],
            $this->back3()->id => ['sheet' => $hdf, 'edge_band' => null],
        ]);

        // 2.761102 m² × 1.2 ÷ (2.44 × 1.22) = 1.113 → 1.12
        $this->assertSame([1.12, 0.07], array_column($lines, 'quantity'));
    }

    public function test_bom_proposal_sums_lines_sharing_a_stock_item()
    {
        $product = Product::factory()->create();
        $doors = PanelMaterial::factory()->create(['name' => 'بدنه ۱۶ میل — درب']);
        ProductPart::factory()->create(['product_id' => $product->id, 'panel_material_id' => $this->body16()->id, 'length_mm' => 970, 'width_mm' => 400, 'count' => 1, 'edge_length' => 1]);
        ProductPart::factory()->create(['product_id' => $product->id, 'panel_material_id' => $doors->id, 'length_mm' => 470, 'width_mm' => 400, 'count' => 2, 'edge_length' => 2, 'edge_width' => 2]);
        [$parts] = $this->cutting()->resolve($product->id, null);
        $sheet = $this->stock(0, ['kind' => 'mdf_sheet', 'unit' => 'sheet']);
        $band = $this->stock(0, ['kind' => 'edge_band', 'unit' => 'meter']);

        $lines = $this->cutting()->proposeBomLines($parts, [
            $this->body16()->id => ['sheet' => $sheet, 'edge_band' => $band],
            $doors->id => ['sheet' => $sheet, 'edge_band' => $band],
        ]);

        // sheets: 0.388 m² → 0.07 and 0.376 m² → 0.07; band: 1.0 m and 2 × (2 × 0.5 + 2 × 0.43) = 3.72 → 3.8
        $this->assertSame([[$sheet->id, 0.14], [$band->id, 4.8]], array_map(fn ($l) => [$l['stock_item']->id, $l['quantity']], $lines));
    }
}
