<?php

namespace Tests\Feature\Production;

use App\Models\ProductPart;
use App\Models\Variation;

class WorkOrderCuttingSheetTest extends CuttingTestCase
{
    public function test_two_units_multiply_counts_and_area()
    {
        $variation = Variation::factory()->create();
        $this->damtv220($variation->product);
        $workOrder = $this->workOrderFor($variation, 2);

        $r = $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/cutting-list", $this->header('Technic'))->assertStatus(200);

        $r->assertJsonPath('work_order.quantity', 2)
            ->assertJsonPath('source', 'product')
            ->assertJsonCount(2, 'groups')
            ->assertJsonPath('groups.0.panel_material.name', 'بدنه ۱۶ میل')
            ->assertJsonPath('groups.0.parts.2.name', 'درب ها')
            ->assertJsonPath('groups.0.parts.2.count', 8)
            ->assertJsonPath('groups.1.parts.0.name', 'پشت بند')
            ->assertJsonPath('summary.0.area_m2', 5.522);
        $this->assertNotNull($r->json('updated_at'));
        $this->assertStringNotContainsString('cost', json_encode($r->json()));
    }

    public function test_uses_the_variations_own_list()
    {
        $variation = Variation::factory()->create();
        $this->damtv220($variation->product);
        ProductPart::factory()->create(['product_id' => $variation->product_id, 'variation_id' => $variation->id, 'name' => 'گردویی', 'count' => 3]);
        $workOrder = $this->workOrderFor($variation, 1);

        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/cutting-list", $this->header('Technic'))
            ->assertStatus(200)
            ->assertJsonPath('source', 'variation')
            ->assertJsonPath('groups.0.parts.0.name', 'گردویی')
            ->assertJsonPath('groups.0.parts.0.count', 3);
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
