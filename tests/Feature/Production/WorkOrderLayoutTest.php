<?php

namespace Tests\Feature\Production;

use App\Enums\WorkOrderState;
use App\Models\CuttingLayout;
use App\Models\ProductDesign;
use App\Models\User;
use App\Models\Variation;

class WorkOrderLayoutTest extends CuttingTestCase
{
    private function designFor(Variation $variation): ProductDesign
    {
        $this->damtv220($variation->product);

        return ProductDesign::where('product_id', $variation->product_id)->where('is_default', true)->firstOrFail();
    }

    private function designLayout(ProductDesign $design, int $units, bool $approve = true): int
    {
        $id = $this->postJson("/api/v1/admin/designs/{$design->id}/layouts", ['name' => "{$units} عدد", 'units' => $units], $this->header('Technic'))
            ->assertStatus(201)->json('data.id');
        if ($approve) {
            $this->postJson("/api/v1/admin/layouts/{$id}/approval", [], $this->header('SuperAdmin'))->assertStatus(200);
        }

        return $id;
    }

    private function read(int $workOrderId)
    {
        return $this->getJson("/api/v1/admin/work-orders/{$workOrderId}/layout", $this->header('Technic'))->assertStatus(200);
    }

    public function test_approved_design_layout_for_the_same_units_is_reused()
    {
        $variation = Variation::factory()->create();
        $design = $this->designFor($variation);
        $two = $this->designLayout($design, 2);
        $this->designLayout($design, 1);

        $workOrder = $this->workOrderFor($variation, 2);
        $this->read($workOrder->id)->assertJsonPath('source', 'design')->assertJsonPath('data.id', $two)->assertJsonPath('data.approved', true);

        // Three units: no layout for that count.
        $three = $this->workOrderFor($variation, 3);
        $this->read($three->id)->assertJsonPath('data', null)->assertJsonPath('source', null)->assertJsonPath('has_parts', true);
    }

    public function test_unapproved_design_layout_is_not_reused()
    {
        $variation = Variation::factory()->create();
        $this->designLayout($this->designFor($variation), 1, false);

        $this->read($this->workOrderFor($variation)->id)->assertJsonPath('data', null);
    }

    public function test_customised_work_order_gets_its_own_layout()
    {
        $variation = Variation::factory()->create();
        $design = $this->designFor($variation);
        $this->designLayout($design, 2);
        $workOrder = $this->workOrderFor($variation, 2);

        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/parts", ['parts' => array_slice($this->damtv220Parts(), 0, 3)], $this->header('Technic'))->assertStatus(200);
        $this->read($workOrder->id)->assertJsonPath('data', null);

        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/layout", ['kerf_mm' => 3], $this->header('Technic'))
            ->assertStatus(200)->assertJsonPath('source', 'work_order')->assertJsonPath('data.units', 2)
            ->assertJsonPath('data.settings.kerf_mm', 3)->assertJsonPath('data.outdated', false);
        // 2 × (1 + 1 + 4) pieces.
        $this->assertSame(12, array_sum(array_map(fn ($s) => count($s['placements']), $this->read($workOrder->id)->json('data.sheets'))));

        // Changing the parts again makes it outdated; it is still returned so it can be regenerated.
        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/parts", ['parts' => array_slice($this->damtv220Parts(), 0, 2)], $this->header('Technic'));
        $this->read($workOrder->id)->assertJsonPath('source', 'work_order')->assertJsonPath('data.outdated', true);

        // Regenerating replaces it rather than adding one.
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/layout", [], $this->header('Technic'))->assertStatus(200)->assertJsonPath('data.outdated', false);
        $this->assertSame(1, CuttingLayout::where('work_order_id', $workOrder->id)->count());
    }

    public function test_design_change_after_the_work_order_was_made_stops_the_reuse()
    {
        $variation = Variation::factory()->create();
        $design = $this->designFor($variation);
        $workOrder = $this->workOrderFor($variation);

        // The design is changed after the work order copied it; the new layout is for the new parts.
        $parts = $this->damtv220Parts();
        $parts[0]['length_mm'] = 1800;
        $this->putJson("/api/v1/admin/designs/{$design->id}/cutting-list", ['parts' => $parts], $this->header('Technic'));
        $this->designLayout($design, 1);

        $this->read($workOrder->id)->assertJsonPath('data', null);
    }

    public function test_permissions_and_closed_work_orders()
    {
        $variation = Variation::factory()->create();
        $this->designFor($variation);
        $workOrder = $this->workOrderFor($variation);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('production.view');
        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/layout", $this->header($viewer))->assertStatus(200);
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/layout", [], $this->header($viewer))->assertStatus(403);

        $workOrder->forceFill(['state' => WorkOrderState::DONE])->save();
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/layout", [], $this->header('Technic'))
            ->assertStatus(422)->assertJsonValidationErrors('layout');

        // A work order's own layout is not managed through the design layout routes.
        $other = $this->workOrderFor($variation);
        $own = $this->postJson("/api/v1/admin/work-orders/{$other->id}/layout", [], $this->header('Technic'))->json('data.id');
        $this->deleteJson("/api/v1/admin/layouts/{$own}", [], $this->header('Technic'))->assertStatus(404);
    }
}
