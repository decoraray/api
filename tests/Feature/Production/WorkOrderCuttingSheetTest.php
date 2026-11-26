<?php

namespace Tests\Feature\Production;

use App\Models\ProductPart;
use App\Models\Variation;

class WorkOrderCuttingSheetTest extends CuttingTestCase
{
    public function test_two_units_multiply_counts_and_area_and_name_the_design()
    {
        $variation = Variation::factory()->create();
        $this->damtv220($variation->product);
        $workOrder = $this->workOrderFor($variation, 2);

        $r = $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/cutting-list", $this->header('Technic'))->assertStatus(200);

        $r->assertJsonPath('work_order.quantity', 2)
            ->assertJsonPath('source', 'work_order')
            ->assertJsonPath('design.name', 'استاندارد')
            ->assertJsonPath('customized', false)
            ->assertJsonCount(2, 'groups')
            ->assertJsonPath('groups.0.sheet.code', 'MDF-16-WAL')
            ->assertJsonPath('groups.0.parts.2.name', 'درب ها')
            ->assertJsonPath('groups.0.parts.2.count', 8)
            ->assertJsonPath('parts.2.count', 4)
            ->assertJsonPath('groups.1.parts.0.name', 'پشت بند')
            ->assertJsonPath('summary.0.area_m2', 5.522);
        $this->assertNotNull($r->json('updated_at'));
        $this->assertStringNotContainsString('cost', json_encode($r->json()));
    }

    public function test_uses_the_variations_default_design()
    {
        $variation = Variation::factory()->create();
        $this->damtv220($variation->product);
        ProductPart::factory()->create(['design_id' => $this->design($variation->product, $variation)->id, 'name' => 'گردویی', 'count' => 3]);
        $workOrder = $this->workOrderFor($variation, 1);

        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/cutting-list", $this->header('Technic'))
            ->assertStatus(200)
            ->assertJsonPath('groups.0.parts.0.name', 'گردویی')
            ->assertJsonPath('groups.0.parts.0.count', 3);
    }

    public function test_design_edits_after_creation_do_not_change_the_sheet()
    {
        $variation = Variation::factory()->create();
        $this->damtv220($variation->product);
        $workOrder = $this->workOrderFor($variation, 1);

        $this->design($variation->product)->parts()->delete();

        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/cutting-list", $this->header('Technic'))
            ->assertJsonCount(7, 'parts');
    }

    public function test_customised_custom_line_shows_its_own_parts()
    {
        $workOrder = $this->workOrderFor(null);
        $parts = array_slice($this->damtv220Parts(), 0, 4);

        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/parts", ['parts' => $parts], $this->header('Technic'))
            ->assertStatus(200)
            ->assertJsonPath('customized', true)
            ->assertJsonCount(4, 'parts');

        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/cutting-list", $this->header('Technic'))
            ->assertJsonPath('groups.0.parts.0.name', 'صفحه رویی')
            ->assertJsonPath('design', null);
    }

    public function test_custom_line_has_no_parts()
    {
        $workOrder = $this->workOrderFor(null);

        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/cutting-list", $this->header('Technic'))
            ->assertStatus(200)
            ->assertJsonPath('source', 'none')
            ->assertJsonPath('groups', [])
            ->assertJsonPath('summary', []);
    }

    public function test_requires_production_view()
    {
        $workOrder = $this->workOrderFor(null);

        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/cutting-list", $this->header('Warehouse'))->assertStatus(403);
    }
}
