<?php

namespace Tests\Feature\Variations;

use App\Models\BomLine;
use App\Models\ProductDesign;
use App\Services\Production\WorkOrderDesignService;

class VariationWorkOrderTest extends VariationTestCase
{
    /** [stock code => planned quantity] */
    private function materialsOf($workOrder): array
    {
        return $workOrder->materials()->with('stockItem')->get()
            ->mapWithKeys(fn ($m) => [$m->stockItem->code => $m->planned_quantity])->sortKeys()->all();
    }

    public function test_a_variation_is_built_from_its_material()
    {
        $design = $this->unit();
        $band = $this->stock(0, ['code' => 'EB-GLOSS', 'kind' => 'edge_band', 'unit' => 'meter']);
        $gloss = $this->gloss();
        $gloss->update(['default_edge_band_id' => $band->id]);
        $hinge = $this->stock(0, ['code' => 'HNG-01']);
        BomLine::create(['design_id' => $design->id, 'stock_item_id' => $hinge->id, 'quantity' => 4]);
        $variation = $this->variationOf($design, $this->material('هایگلاس', $gloss));

        $workOrder = $this->workOrderFor($variation, 2)->refresh();

        $materials = $this->materialsOf($workOrder);
        $this->assertSame(['EB-GLOSS', 'HDF-3-WH', 'HIGLOSS16', 'HNG-01'], array_keys($materials));
        $this->assertSame(8.0, $materials['HNG-01']);
        // 2.761 m² × 2 units × 1.2 ÷ 3.416 m² = 1.94 sheets.
        $this->assertSame(1.94, $materials['HIGLOSS16']);
        $this->assertFalse($workOrder->customized);
        $this->assertSame('هایگلاس', $workOrder->material_name);
        $this->assertSame([$gloss->id, $this->back3()->id], $workOrder->parts()->pluck('stock_item_id')->unique()->values()->all());
        // The design keeps its walnut.
        $this->assertContains($this->body16()->id, $design->parts()->pluck('stock_item_id')->all());
    }

    public function test_a_chosen_design_is_still_built_in_the_variations_material()
    {
        $design = $this->unit();
        $other = ProductDesign::factory()->create(['product_id' => $design->product_id, 'name' => '۱۸۰']);
        $this->damtv220On($other);
        $variation = $this->variationOf($design, $this->material('هایگلاس', $this->gloss()));

        $workOrder = $this->workOrderFor($variation, 1, $other)->refresh();

        $this->assertSame($other->id, $workOrder->design_id);
        $this->assertContains($this->gloss()->id, $workOrder->parts()->pluck('stock_item_id')->all());
        $this->assertNotContains($this->body16()->id, $workOrder->parts()->pluck('stock_item_id')->all());
    }

    public function test_without_sheets_or_a_variation_the_design_is_copied_as_it_is()
    {
        $design = $this->unit();
        $variation = $this->variationOf($design, $this->material('چرم'));

        $workOrder = $this->workOrderFor($variation)->refresh();

        $this->assertNull($workOrder->material_name);
        $this->assertContains($this->body16()->id, $workOrder->parts()->pluck('stock_item_id')->all());
        $this->assertNull($this->workOrderFor(null)->design_id);
    }

    public function test_switching_design_builds_in_the_material_too()
    {
        $design = $this->unit();
        $other = ProductDesign::factory()->create(['product_id' => $design->product_id, 'name' => '۱۸۰']);
        $this->damtv220On($other);
        $variation = $this->variationOf($design, $this->material('هایگلاس', $this->gloss()));
        $workOrder = $this->workOrderFor($variation);

        app(WorkOrderDesignService::class)->switchDesign($workOrder, $other);

        $this->assertContains($this->gloss()->id, $workOrder->parts()->pluck('stock_item_id')->all());
        $this->assertSame('هایگلاس', $workOrder->fresh()->material_name);
    }
}
