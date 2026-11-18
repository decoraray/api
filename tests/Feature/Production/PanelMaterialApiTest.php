<?php

namespace Tests\Feature\Production;

use App\Models\PanelMaterial;
use App\Models\ProductPart;

class PanelMaterialApiTest extends CuttingTestCase
{
    public function test_lists_for_production_view_and_bom_manage()
    {
        foreach (['Sales', 'Technic'] as $role) {
            $this->getJson('/api/v1/admin/panel-materials', $this->header($role))
                ->assertStatus(200)
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('data.0.name', 'پشت‌بند ۳ میل');
        }

        $this->getJson('/api/v1/admin/panel-materials', $this->header('Warehouse'))->assertStatus(403);
    }

    public function test_creates_updates_and_deactivates()
    {
        $headers = $this->header('Technic');

        $id = $this->postJson('/api/v1/admin/panel-materials', ['name' => 'بدنه ۸ میل', 'thickness_mm' => 8], $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.sheet_length_mm', 3660)
            ->assertJsonPath('data.waste_percent', 20)
            ->json('data.id');

        $this->putJson("/api/v1/admin/panel-materials/{$id}", ['name' => 'بدنه ۸ میل', 'thickness_mm' => 8, 'waste_percent' => 15, 'active' => false], $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.waste_percent', 15)
            ->assertJsonPath('data.active', false);

        $this->getJson('/api/v1/admin/panel-materials?active=1', $headers)->assertJsonCount(2, 'data');

        $this->postJson('/api/v1/admin/panel-materials', ['name' => 'بدنه ۸ میل', 'thickness_mm' => 8], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/admin/panel-materials', ['name' => 'ورق', 'thickness_mm' => 8, 'waste_percent' => 101], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('waste_percent');
    }

    public function test_used_material_cannot_be_deleted()
    {
        $headers = $this->header('Technic');
        $used = $this->body16();
        ProductPart::factory()->create(['panel_material_id' => $used->id]);
        $unused = PanelMaterial::factory()->create();

        $this->deleteJson("/api/v1/admin/panel-materials/{$used->id}", [], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('panel_material');
        $this->assertNotNull($used->fresh());

        $this->deleteJson("/api/v1/admin/panel-materials/{$unused->id}", [], $headers)->assertStatus(200);
        $this->assertNull($unused->fresh());
    }

    public function test_sales_cannot_write()
    {
        $headers = $this->header('Sales');
        $material = $this->body16();

        $this->postJson('/api/v1/admin/panel-materials', ['name' => 'x', 'thickness_mm' => 8], $headers)->assertStatus(403);
        $this->putJson("/api/v1/admin/panel-materials/{$material->id}", ['name' => 'x', 'thickness_mm' => 8], $headers)->assertStatus(403);
        $this->deleteJson("/api/v1/admin/panel-materials/{$material->id}", [], $headers)->assertStatus(403);
        $this->assertSame('بدنه ۱۶ میل', $material->fresh()->name);
    }
}
