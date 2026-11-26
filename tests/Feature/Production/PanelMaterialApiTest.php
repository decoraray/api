<?php

namespace Tests\Feature\Production;

/** Panel materials were replaced by MDF sheet stock items; the list answers empty for one release. */
class PanelMaterialApiTest extends CuttingTestCase
{
    public function test_the_deprecated_list_is_empty_and_writes_are_gone()
    {
        $this->getJson('/api/v1/admin/panel-materials', $this->header('Technic'))->assertStatus(200)->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/admin/panel-materials', $this->header('Warehouse'))->assertStatus(403);

        $this->postJson('/api/v1/admin/panel-materials', ['name' => 'بدنه ۸ میل', 'thickness_mm' => 8], $this->header('SuperAdmin'))->assertStatus(405);
    }

    public function test_sheet_items_list_active_mdf_sheets_without_cost()
    {
        $this->body16();
        $this->back3();
        \App\Models\StockItem::factory()->mdf()->create(['code' => 'OLD', 'active' => false]);
        \App\Models\StockItem::factory()->create(['code' => 'HNG-01']);

        $r = $this->getJson('/api/v1/admin/sheet-items', $this->header('Technic'))->assertStatus(200);

        $this->assertSame(['MDF-16-WAL', 'HDF-3-WH'], array_column($r->json('data'), 'code'));
        $r->assertJsonPath('data.0.waste_percent', 20)->assertJsonPath('data.0.sheet_length_mm', 3660);
        $this->assertStringNotContainsString('cost', json_encode($r->json()));
        $this->assertCount(3, $this->getJson('/api/v1/admin/sheet-items?include_inactive=1', $this->header('Technic'))->json('data'));
        $this->getJson('/api/v1/admin/sheet-items', $this->header('Warehouse'))->assertStatus(403);
    }
}
