<?php

namespace Tests\Feature\Production;

use App\Enums\OrderStatus;
use App\Models\BomLine;
use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\User;
use App\Models\Variation;
use App\Models\WorkOrder;

class WorkOrderDesignTest extends CuttingTestCase
{
    /** DAMTV220 with «۲۲۰ سانتی» (default, 7 parts, 1.5 sheets + 4 hinges) and «۱۸۰ سانتی» (1 part, 1 sheet). */
    private function twoSizes(): array
    {
        $variation = Variation::factory()->create();
        $big = $this->design($variation->product);
        $big->update(['name' => '۲۲۰ سانتی']);
        $this->damtv220On($big);
        $sheet = $this->stock(10, ['kind' => 'mdf_sheet', 'unit' => 'sheet']);
        $hinge = $this->stock(20);
        BomLine::create(['design_id' => $big->id, 'stock_item_id' => $sheet->id, 'quantity' => 1.5]);
        BomLine::create(['design_id' => $big->id, 'stock_item_id' => $hinge->id, 'quantity' => 4]);

        $small = ProductDesign::factory()->create(['product_id' => $variation->product_id, 'name' => '۱۸۰ سانتی']);
        $this->damtv220On($small)->parts()->where('position', '>', 0)->delete();
        BomLine::create(['design_id' => $small->id, 'stock_item_id' => $sheet->id, 'quantity' => 1]);

        return [$variation, $big, $small, $sheet, $hinge];
    }

    // --- creation ---------------------------------------------------------------

    public function test_work_order_copies_the_chosen_design()
    {
        [$variation, , $small, $sheet] = $this->twoSizes();
        $workOrder = $this->workOrderFor($variation, 2, $small);

        $this->assertSame([$small->id, '۱۸۰ سانتی', false], [$workOrder->design_id, $workOrder->design_name, $workOrder->customized]);
        $this->assertSame(1, $workOrder->parts()->count());
        $this->assertSame([[$sheet->id, 2.0]], $workOrder->materials->map(fn ($m) => [$m->stock_item_id, $m->planned_quantity])->all());
    }

    public function test_work_order_falls_back_to_the_default_and_ignores_later_edits()
    {
        [$variation, $big] = $this->twoSizes();
        $workOrder = $this->workOrderFor($variation, 2);

        $this->assertSame('۲۲۰ سانتی', $workOrder->design_name);
        $this->assertSame(7, $workOrder->parts()->count());
        $this->assertEquals([3.0, 8.0], $workOrder->materials->pluck('planned_quantity')->all());

        $big->parts()->delete();
        $big->bomLines()->delete();
        $this->assertSame(7, $workOrder->parts()->count());
        $this->assertSame(2, $workOrder->materials()->count());

        // Confirming again creates nothing new.
        $this->service()->createForOrder($workOrder->order);
        $this->assertSame(7, $workOrder->parts()->count());
        $this->assertSame(1, WorkOrder::count());
    }

    public function test_custom_line_with_and_without_a_design()
    {
        [, , $small] = $this->twoSizes();

        $bare = $this->workOrderFor(null);
        $this->assertSame([null, 0, 0], [$bare->design_id, $bare->parts()->count(), $bare->materials()->count()]);

        $withDesign = $this->workOrderFor(null, 1, $small);
        $this->assertSame([$small->id, 1], [$withDesign->design_id, $withDesign->parts()->count()]);
    }

    // --- editing on the work order --------------------------------------------------

    public function test_parts_can_be_customised_until_done()
    {
        $workOrder = $this->workOrderFor(null);
        $headers = $this->header('Technic');
        $url = "/api/v1/admin/work-orders/{$workOrder->id}/parts";

        $this->putJson($url, ['parts' => array_slice($this->damtv220Parts(), 0, 4)], $headers)
            ->assertStatus(200)->assertJsonPath('customized', true)->assertJsonCount(4, 'parts');
        $this->putJson($url, ['parts' => [['name' => 'x', 'length_mm' => 100, 'width_mm' => 100, 'count' => 1, 'panel_material_id' => $this->body16()->id, 'edge_length' => 3, 'edge_width' => 0]]], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('parts.0.edge_length');
        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}", $headers)->assertJsonPath('data.customized', true);

        $workOrder->update(['state' => 'done']);
        $this->putJson($url, ['parts' => []], $headers)->assertStatus(422)->assertJsonValidationErrors('parts');
        $this->assertSame(4, $workOrder->parts()->count());
    }

    public function test_editing_materials_marks_customised()
    {
        [$variation, , , $sheet] = $this->twoSizes();
        $workOrder = $this->workOrderFor($variation);

        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/materials", ['lines' => [['stock_item_id' => $sheet->id, 'quantity' => 2]]], $this->header('Technic'))
            ->assertStatus(200);
        $this->assertTrue($workOrder->fresh()->customized);
    }

    public function test_materials_proposed_from_parts_for_two_units()
    {
        $workOrder = $this->workOrderFor(null, 2);
        $headers = $this->header('Technic');
        $walnut = $this->stock(0, ['code' => 'MDF-16-WAL', 'kind' => 'mdf_sheet', 'unit' => 'sheet']);
        $hdf = $this->stock(0, ['code' => 'HDF-3-WH', 'kind' => 'mdf_sheet', 'unit' => 'sheet']);
        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/parts", ['parts' => $this->damtv220Parts()], $headers)->assertStatus(200);
        $url = "/api/v1/admin/work-orders/{$workOrder->id}/parts/bom-lines";

        $this->postJson($url, [], $headers)->assertStatus(422)->assertJsonValidationErrors('mapping');
        $this->postJson($url, ['mapping' => [
            ['panel_material_id' => $this->body16()->id, 'sheet_stock_item_id' => $walnut->id],
            ['panel_material_id' => $this->back3()->id, 'sheet_stock_item_id' => $hdf->id],
        ]], $headers)->assertStatus(200)
            // 5.522 m² × 1.2 ÷ 6.698 m² → 0.99 for two units
            ->assertJsonPath('data.0.quantity', 0.99)
            ->assertJsonPath('panel_map.0.sheet_stock_item.code', 'MDF-16-WAL');

        $this->postJson($url, [], $headers)->assertStatus(200)->assertJsonPath('data.0.quantity', 0.99);
        $this->assertSame(0, $workOrder->materials()->count());
    }

    // --- switching ---------------------------------------------------------------

    public function test_switch_before_start()
    {
        [$variation, , $small] = $this->twoSizes();
        $workOrder = $this->workOrderFor($variation);
        $this->assertSame(7, $workOrder->parts()->count());

        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}", $this->header('Technic'))
            ->assertJsonPath('data.design_switchable', true)->assertJsonPath('data.product_id', $variation->product_id);

        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/design", ['design_id' => $small->id], $this->header('Technic'))
            ->assertStatus(200)->assertJsonPath('design.name', '۱۸۰ سانتی')->assertJsonCount(1, 'parts');
        $this->assertEquals([1.0], $workOrder->materials()->pluck('planned_quantity')->all());
    }

    public function test_switch_refused_when_reserved_or_foreign()
    {
        [$variation, , $small] = $this->twoSizes();
        $workOrder = $this->workOrderFor($variation);
        $headers = $this->header('Technic');

        $foreign = ProductDesign::factory()->create();
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/design", ['design_id' => $foreign->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('design_id');

        $this->materials()->reserve($workOrder);
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/design", ['design_id' => $small->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('design_id');
        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}", $headers)->assertJsonPath('data.design_switchable', false);
        $this->assertSame(7, $workOrder->parts()->count());
    }

    public function test_custom_line_can_switch_to_any_design()
    {
        [, , $small] = $this->twoSizes();
        $workOrder = $this->workOrderFor(null);

        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/design", ['design_id' => $small->id], $this->header('Technic'))
            ->assertStatus(200)->assertJsonCount(1, 'parts');
    }

    public function test_view_only_users_cannot_write()
    {
        $workOrder = $this->workOrderFor(null);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('production.view');
        $headers = $this->header($viewer);

        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/parts", ['parts' => []], $headers)->assertStatus(403);
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/parts/bom-lines", [], $headers)->assertStatus(403);
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/design", ['design_id' => 1], $headers)->assertStatus(403);
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/save-as-design", ['mode' => 'new', 'name' => 'x'], $headers)->assertStatus(403);
    }
}
