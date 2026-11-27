<?php

namespace Tests\Feature\Production;

use App\Models\BomLine;
use App\Models\CostAdder;
use App\Models\Variation;

/** Sheet and edge band lines follow the parts (cutting-list "Calculated sheet and edge band lines"). */
class CalculatedLinesTest extends CuttingTestCase
{
    private function putParts($design, array $parts, string $role = 'Technic')
    {
        return $this->putJson("/api/v1/admin/designs/{$design->id}/cutting-list", ['parts' => $parts], $this->header($role));
    }

    /** [code, quantity, calculated?] of a design's BOM, ordered by code. */
    private function bomOf($design): array
    {
        return $design->bomLines()->with('stockItem')->get()->sortBy('stockItem.code')
            ->map(fn ($l) => [$l->stockItem->code, $l->quantity, $l->calculated_quantity !== null])->values()->all();
    }

    public function test_saving_parts_updates_the_bom()
    {
        $design = $this->design(\App\Models\Product::factory()->create(['dimensions' => '200*40*40']));
        $this->edgeBand();
        $hinge = $this->stock(0, ['code' => 'HNG-01']);
        BomLine::create(['design_id' => $design->id, 'stock_item_id' => $hinge->id, 'quantity' => 4]);

        $this->putParts($design, $this->damtv220Parts())->assertStatus(200);

        // 2.761 m² × 1.2 ÷ 6.698 m² = 0.50 sheet; «درب ها» 4 × 2 × 31.3 cm = 2.6 m; the back 0.07 sheet, no band.
        $this->assertSame([['EB-WAL-2', 2.6, true], ['HDF-3-WH', 0.07, true], ['HNG-01', 4.0, false], ['MDF-16-WAL', 0.5, true]], $this->bomOf($design));

        $r = $this->getJson("/api/v1/admin/designs/{$design->id}/bom", $this->header('Technic'))->assertStatus(200);
        $flags = collect($r->json('data'))->mapWithKeys(fn ($l) => [$l['stock_item']['code'] => $l['calculated']])->all();
        $this->assertSame(['EB-WAL-2' => true, 'HDF-3-WH' => true, 'HNG-01' => false, 'MDF-16-WAL' => true], collect($flags)->sortKeys()->all());
    }

    public function test_moving_parts_to_another_sheet_moves_the_line()
    {
        $design = $this->damtv220On($this->design(\App\Models\Product::factory()->create()));
        $this->putParts($design, $this->damtv220Parts())->assertStatus(200);
        $white = $this->stock(0, ['code' => 'MDF-16-WH', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16, 'length_mm' => 3660, 'width_mm' => 1830, 'waste_percent' => 20]);

        $parts = array_map(fn ($p) => $p['stock_item_id'] === $this->body16()->id ? ['stock_item_id' => $white->id] + $p : $p, $this->damtv220Parts());
        $this->putParts($design, $parts)->assertStatus(200);

        $this->assertSame([['HDF-3-WH', 0.07, true], ['MDF-16-WH', 0.5, true]], $this->bomOf($design));
    }

    public function test_an_empty_list_removes_the_calculated_lines_only()
    {
        $design = $this->design(\App\Models\Product::factory()->create());
        $hinge = $this->stock(0, ['code' => 'HNG-01']);
        BomLine::create(['design_id' => $design->id, 'stock_item_id' => $hinge->id, 'quantity' => 4]);
        $this->putParts($design, $this->damtv220Parts())->assertStatus(200);

        $this->putParts($design, [])->assertStatus(200);

        $this->assertSame([['HNG-01', 4.0, false]], $this->bomOf($design));
    }

    public function test_hand_typed_sheet_lines_give_way_when_parts_are_first_saved()
    {
        $design = $this->design(\App\Models\Product::factory()->create());
        BomLine::create(['design_id' => $design->id, 'stock_item_id' => $this->body16()->id, 'quantity' => 1.5]);

        $this->putParts($design, $this->damtv220Parts())->assertStatus(200);

        $this->assertSame([['HDF-3-WH', 0.07, true], ['MDF-16-WAL', 0.5, true]], $this->bomOf($design));
    }

    public function test_a_stock_linked_adder_stays_on_top_of_the_calculated_quantity()
    {
        $design = $this->design(\App\Models\Product::factory()->create());
        $band = $this->edgeBand();
        $this->putParts($design, $this->damtv220Parts())->assertStatus(200);
        $adder = CostAdder::create(['name' => 'نوار اضافه', 'group' => 'misc', 'basis' => 'stock', 'stock_item_id' => $band->id]);
        $this->postJson("/api/v1/admin/designs/{$design->id}/adders", ['cost_adder_id' => $adder->id, 'quantity' => 2], $this->header('Technic'))->assertStatus(201);
        $this->assertSame(4.6, $design->bomLines()->where('stock_item_id', $band->id)->value('quantity') + 0.0);

        // Doubling the doors doubles the band from the parts; the adder's 2 m stay.
        $parts = $this->damtv220Parts();
        $parts[2]['count'] = 8;
        $this->putParts($design, $parts)->assertStatus(200);

        $this->assertSame(7.1, (float) $design->bomLines()->where('stock_item_id', $band->id)->value('quantity'));
    }

    public function test_a_sheet_counted_in_square_metres_gets_the_area_with_waste()
    {
        $design = $this->design(\App\Models\Product::factory()->create());
        $m2 = $this->stock(0, ['code' => 'MDF-16-M2', 'kind' => 'mdf_sheet', 'unit' => 'square_metre', 'thickness_mm' => 16, 'waste_percent' => 20]);
        $parts = array_map(fn ($p) => $p['stock_item_id'] === $this->body16()->id ? ['stock_item_id' => $m2->id] + $p : $p, $this->damtv220Parts());

        $this->putParts($design, $parts)->assertStatus(200);

        // 2.761 m² with 20% waste = 3.3132, rounded up to 3.32 m²; the HDF stays in sheets.
        $this->assertSame([['HDF-3-WH', 0.07, true], ['MDF-16-M2', 3.32, true]], $this->bomOf($design));
    }

    public function test_bom_put_refuses_sheets_on_a_design_with_parts_and_keeps_calculated_lines()
    {
        $design = $this->design(\App\Models\Product::factory()->create());
        $hinge = $this->stock(0, ['code' => 'HNG-01']);
        $this->putParts($design, $this->damtv220Parts())->assertStatus(200);
        $url = "/api/v1/admin/designs/{$design->id}/bom";

        $this->putJson($url, ['lines' => [['stock_item_id' => $this->body16()->id, 'quantity' => 2]]], $this->header('Technic'))
            ->assertStatus(422)->assertJsonValidationErrors('lines');

        $this->putJson($url, ['lines' => [['stock_item_id' => $hinge->id, 'quantity' => 6]]], $this->header('Technic'))->assertStatus(200);
        $this->assertSame([['HDF-3-WH', 0.07, true], ['HNG-01', 6.0, false], ['MDF-16-WAL', 0.5, true]], $this->bomOf($design));
    }

    public function test_a_design_without_parts_takes_sheets_by_hand()
    {
        $design = $this->design(\App\Models\Product::factory()->create());

        $this->putJson("/api/v1/admin/designs/{$design->id}/bom", ['lines' => [['stock_item_id' => $this->body16()->id, 'quantity' => 1.5]]], $this->header('Technic'))
            ->assertStatus(200);

        $this->assertSame([['MDF-16-WAL', 1.5, false]], $this->bomOf($design));
    }

    public function test_work_order_parts_plan_sheets_for_its_quantity()
    {
        $variation = Variation::factory()->create();
        $workOrder = $this->workOrderFor($variation, 2);
        $parts = [['name' => 'بغل', 'length_mm' => 1000, 'width_mm' => 1000, 'count' => 2, 'stock_item_id' => $this->body16()->id, 'edge_length' => 0, 'edge_width' => 0]];

        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/parts", ['parts' => $parts], $this->header('Technic'))->assertStatus(200);

        // 2 units × 2 m² = 4.0 m² × 1.2 ÷ 6.6978 = 0.7166… → 0.72 sheet.
        $line = $workOrder->materials()->firstOrFail();
        $this->assertSame([$this->body16()->id, 0.72, 0.72], [$line->stock_item_id, $line->planned_quantity, $line->calculated_quantity]);
        $this->assertTrue($workOrder->refresh()->customized);
    }

    public function test_work_order_parts_cannot_undercut_what_was_consumed()
    {
        $variation = Variation::factory()->create();
        $workOrder = $this->workOrderFor($variation);
        app(\App\Services\Inventory\InventoryService::class)->receive($this->body16(), 5, 0, null);
        $big = [['name' => 'بغل', 'length_mm' => 3000, 'width_mm' => 1500, 'count' => 1, 'stock_item_id' => $this->body16()->id, 'edge_length' => 0, 'edge_width' => 0]];
        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/parts", ['parts' => $big], $this->header('Technic'))->assertStatus(200);
        $material = $workOrder->materials()->firstOrFail();
        $this->materials()->reserve($workOrder);
        $this->materials()->consume($material->fresh(), 0.8, 0, null);

        $small = [['name' => 'بغل', 'length_mm' => 1000, 'width_mm' => 500, 'count' => 1, 'stock_item_id' => $this->body16()->id, 'edge_length' => 0, 'edge_width' => 0]];
        $r = $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/parts", ['parts' => $small], $this->header('Technic'))
            ->assertStatus(422)->assertJsonValidationErrors('parts');

        $this->assertStringContainsString('MDF-16-WAL', $r->json('errors.parts.0'));
        $this->assertSame(3000, $workOrder->parts()->value('length_mm'));
    }

    public function test_work_order_material_put_refuses_sheets_when_it_has_parts()
    {
        $variation = Variation::factory()->create();
        $workOrder = $this->workOrderFor($variation);
        $hinge = $this->stock(0, ['code' => 'HNG-01']);
        $parts = [['name' => 'بغل', 'length_mm' => 1000, 'width_mm' => 1000, 'count' => 1, 'stock_item_id' => $this->body16()->id, 'edge_length' => 0, 'edge_width' => 0]];
        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/parts", ['parts' => $parts], $this->header('Technic'))->assertStatus(200);
        $url = "/api/v1/admin/work-orders/{$workOrder->id}/materials";

        $this->putJson($url, ['lines' => [['stock_item_id' => $this->body16()->id, 'quantity' => 3]]], $this->header('Technic'))->assertStatus(422);

        $this->putJson($url, ['lines' => [['stock_item_id' => $hinge->id, 'quantity' => 4]]], $this->header('Technic'))->assertStatus(200);
        $this->assertEqualsCanonicalizing([$this->body16()->id, $hinge->id], $workOrder->materials()->pluck('stock_item_id')->all());
    }

    /** PUT a sheet item with some fields changed, as the admin stock-item form sends it. */
    private function editSheet(\App\Models\StockItem $sheet, array $over)
    {
        $payload = $over + $sheet->only(['code', 'name', 'thickness_mm', 'color', 'length_mm', 'width_mm', 'sheet_type', 'waste_percent', 'default_edge_band_id', 'active'])
            + ['kind' => 'mdf_sheet', 'unit' => 'sheet'];

        return $this->putJson("/api/v1/admin/stock-items/{$sheet->id}", $payload, $this->header('SuperAdmin'));
    }

    private function sheetLine($design, \App\Models\StockItem $sheet): float
    {
        return (float) $design->bomLines()->where('stock_item_id', $sheet->id)->value('quantity');
    }

    public function test_resizing_a_sheet_recalculates_the_designs_cut_from_it()
    {
        $design = $this->design(\App\Models\Product::factory()->create());
        $hinge = $this->stock(0, ['code' => 'HNG-01']);
        $this->putParts($design, $this->damtv220Parts())->assertStatus(200);
        BomLine::create(['design_id' => $design->id, 'stock_item_id' => $hinge->id, 'quantity' => 4]);
        $design->forceFill(['approved_at' => now()])->save();
        $this->assertSame(0.5, $this->sheetLine($design, $this->body16()));

        // High-gloss board: 122 × 280 cm.
        $this->editSheet($this->body16(), ['length_mm' => 2800, 'width_mm' => 1220])->assertStatus(200);

        // The same parts saved now give the same quantity.
        $fresh = $this->design(\App\Models\Product::factory()->create());
        $this->putParts($fresh, $this->damtv220Parts())->assertStatus(200);
        $expected = $this->sheetLine($fresh, $this->body16());
        $this->assertGreaterThan(0.5, $expected);
        $this->assertSame($expected, $this->sheetLine($design, $this->body16()));
        // The back sheet, the hand-added hinge and the approval are untouched.
        $this->assertSame(0.07, $this->sheetLine($design, $this->back3()));
        $this->assertSame(4.0, $this->sheetLine($design, $hinge));
        $this->assertNotNull($design->fresh()->approved_at);
    }

    public function test_changing_waste_recalculates_and_a_draft_stays_untouched()
    {
        $design = $this->design(\App\Models\Product::factory()->create());
        $this->putParts($design, $this->damtv220Parts())->assertStatus(200);
        $designs = app(\App\Services\Production\DesignService::class);
        $design->forceFill(['draft_hash' => $designs->contentHash($design)])->save();
        $edited = $this->design(\App\Models\Product::factory()->create());
        $this->putParts($edited, $this->damtv220Parts())->assertStatus(200);
        $edited->forceFill(['draft_hash' => 'edited-by-hand'])->save();

        $this->editSheet($this->body16(), ['waste_percent' => 10])->assertStatus(200);

        // 2.761 m² × 1.1 ÷ 6.698 m² = 0.46 sheet.
        $this->assertSame(0.46, $this->sheetLine($design, $this->body16()));
        $this->assertSame($designs->contentHash($design), $design->fresh()->draft_hash);
        $this->assertSame('edited-by-hand', $edited->fresh()->draft_hash);
    }

    public function test_editing_only_the_name_recalculates_nothing()
    {
        $design = $this->design(\App\Models\Product::factory()->create());
        $this->putParts($design, $this->damtv220Parts())->assertStatus(200);
        $line = $design->bomLines()->where('stock_item_id', $this->body16()->id)->first();
        $line->update(['quantity' => 0.9, 'calculated_quantity' => 0.9]); // stale on purpose: a recalculation would bring it back to 0.5

        $this->editSheet($this->body16(), ['name' => 'ام دی اف هایگلاس'])->assertStatus(200);

        $this->assertSame(0.9, (float) $line->fresh()->quantity);
    }

    public function test_the_catch_up_command_recalculates_from_the_current_size()
    {
        $design = $this->design(\App\Models\Product::factory()->create());
        $this->putParts($design, $this->damtv220Parts())->assertStatus(200);
        // Resized before editing recalculated anything.
        $this->body16()->update(['waste_percent' => 10]);

        $this->artisan('designs:recalculate-sheets', ['codes' => ['MDF-16-WAL']])->assertSuccessful();

        $this->assertSame(0.46, $this->sheetLine($design, $this->body16()));
    }
}
