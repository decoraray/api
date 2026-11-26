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
        $this->putJson($url, ['parts' => [['name' => 'x', 'length_mm' => 100, 'width_mm' => 100, 'count' => 1, 'stock_item_id' => $this->body16()->id, 'edge_length' => 3, 'edge_width' => 0]]], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('parts.0.edge_length');
        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}", $headers)->assertJsonPath('data.customized', true);

        $workOrder->update(['state' => 'done']);
        $this->putJson($url, ['parts' => []], $headers)->assertStatus(422)->assertJsonValidationErrors('parts');
        $this->assertSame(4, $workOrder->parts()->count());
    }

    public function test_editing_materials_marks_customised()
    {
        [$variation, , , , $hinge] = $this->twoSizes();
        $workOrder = $this->workOrderFor($variation);

        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/materials", ['lines' => [['stock_item_id' => $hinge->id, 'quantity' => 2]]], $this->header('Technic'))
            ->assertStatus(200);
        $this->assertTrue($workOrder->fresh()->customized);
    }

    // --- colour ----------------------------------------------------------------------

    public function test_work_order_takes_the_line_colour_and_keeps_it_as_a_label()
    {
        [$variation, $big] = $this->twoSizes();
        $white = \App\Models\Color::create(['name' => 'سفید']);
        $walnut = \App\Models\Color::create(['name' => 'گردویی']);
        $variation->update(['color_id' => $white->id]);
        $order = Order::factory()->create(['status' => OrderStatus::CONFIRMED->value]);
        $item = Item::create(['title' => 'x', 'slug' => 'x', 'price' => 1, 'quantity' => 1, 'order_id' => $order->id,
            'product_id' => $variation->product_id, 'variation_id' => $variation->id, 'color_id' => $white->id]);
        $workOrder = $this->service()->createForOrder($order)[0];
        $planned = $workOrder->materials()->pluck('planned_quantity', 'stock_item_id')->all();

        $this->assertSame($white->id, $workOrder->color_id);
        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/cutting-list", $this->header('Technic'))
            ->assertJsonPath('work_order.colour.name', 'سفید');

        $this->patchJson("/api/v1/admin/work-orders/{$workOrder->id}/colour", ['color_id' => $walnut->id], $this->header('Technic'))
            ->assertStatus(200)->assertJsonPath('work_order.colour.name', 'گردویی');
        $fresh = $workOrder->fresh();
        $this->assertSame([$walnut->id, false], [$fresh->color_id, $fresh->customized]);
        $this->assertSame($planned, $fresh->materials()->pluck('planned_quantity', 'stock_item_id')->all());

        // Switching design keeps the colour.
        $small = ProductDesign::where('name', '۱۸۰ سانتی')->firstOrFail();
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/design", ['design_id' => $small->id], $this->header('Technic'))->assertStatus(200);
        $this->assertSame($walnut->id, $workOrder->fresh()->color_id);

        $workOrder->forceFill(['state' => \App\Enums\WorkOrderState::DONE])->save();
        $this->patchJson("/api/v1/admin/work-orders/{$workOrder->id}/colour", ['color_id' => $white->id], $this->header('Technic'))->assertStatus(422);
        $this->patchJson("/api/v1/admin/work-orders/{$workOrder->id}/colour", ['color_id' => $white->id], $this->header('Sales'))->assertStatus(403);
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
        $this->patchJson("/api/v1/admin/work-orders/{$workOrder->id}/colour", ['color_id' => null], $headers)->assertStatus(403);
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/design", ['design_id' => 1], $headers)->assertStatus(403);
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/save-as-design", ['mode' => 'new', 'name' => 'x'], $headers)->assertStatus(403);
    }
}
