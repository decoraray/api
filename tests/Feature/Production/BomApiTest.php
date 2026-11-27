<?php

namespace Tests\Feature\Production;

use App\Models\BomLine;
use App\Models\Variation;

class BomApiTest extends MaterialTestCase
{
    public function test_sets_and_reads_a_product_bom()
    {
        $variation = Variation::factory()->create();
        $sheet = $this->stock(0, ['code' => 'MDF-16-WH', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16]);
        $hinge = $this->stock(0);
        $url = "/api/v1/admin/products/{$variation->product_id}/bom";

        $this->putJson($url, ['lines' => [
            ['stock_item_id' => $sheet->id, 'quantity' => 1.5],
            ['stock_item_id' => $hinge->id, 'quantity' => 4, 'note' => 'کلیپسی'],
        ]], $this->header('Technic'))->assertStatus(200)->assertJsonPath('source', 'product');

        $r = $this->getJson($url, $this->header('Sales'))->assertStatus(200);
        $r->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.stock_item.code', 'MDF-16-WH')
            ->assertJsonPath('data.0.stock_item.unit_label', 'ورق')
            ->assertJsonPath('data.0.quantity', 1.5)
            ->assertJsonPath('data.1.note', 'کلیپسی');
        $this->assertStringNotContainsString('cost', json_encode($r->json()));
    }

    public function test_variation_bom_overrides_and_others_fall_back()
    {
        $walnut = Variation::factory()->create();
        $other = Variation::factory()->create(['product_id' => $walnut->product_id]);
        $white = $this->stock(0);
        $brown = $this->stock(0);
        $url = "/api/v1/admin/products/{$walnut->product_id}/bom";
        $headers = $this->header('Technic');

        $this->putJson($url, ['lines' => [['stock_item_id' => $white->id, 'quantity' => 1.5]]], $headers)->assertStatus(200);
        $this->putJson($url, ['variation_id' => $walnut->id, 'lines' => [['stock_item_id' => $brown->id, 'quantity' => 1.5]]], $headers)
            ->assertStatus(200)->assertJsonPath('source', 'variation');

        $this->getJson("{$url}?variation_id={$walnut->id}", $headers)->assertJsonPath('data.0.stock_item.id', $brown->id)->assertJsonPath('source', 'variation');
        $this->getJson("{$url}?variation_id={$other->id}", $headers)->assertJsonPath('data.0.stock_item.id', $white->id)->assertJsonPath('source', 'product');

        // clearing the variation's own BOM puts it back on the product's
        $this->putJson($url, ['variation_id' => $walnut->id, 'lines' => []], $headers)->assertStatus(200)->assertJsonPath('source', 'product');
        $this->assertSame(1, BomLine::count());
    }

    public function test_invalid_boms_are_rejected_and_change_nothing()
    {
        $variation = Variation::factory()->create();
        $foreign = Variation::factory()->create();
        $sheet = $this->stock(0);
        $this->bom($variation->product, [[$sheet, 2]]);
        $url = "/api/v1/admin/products/{$variation->product_id}/bom";
        $headers = $this->header('Technic');

        $this->putJson($url, ['lines' => [['stock_item_id' => $sheet->id, 'quantity' => 1], ['stock_item_id' => $sheet->id, 'quantity' => 2]]], $headers)->assertStatus(422);
        $this->putJson($url, ['lines' => [['stock_item_id' => $sheet->id, 'quantity' => 0]]], $headers)->assertStatus(422);
        $this->putJson($url, ['lines' => [['stock_item_id' => $sheet->id, 'quantity' => null]]], $headers)->assertStatus(422)
            ->assertJsonFragment(['مقدار هر ماده را وارد کنید.']);
        $this->putJson($url, ['lines' => [['stock_item_id' => 99999, 'quantity' => 1]]], $headers)->assertStatus(422);
        $this->putJson($url, ['variation_id' => $foreign->id, 'lines' => []], $headers)->assertStatus(422);
        $this->putJson($url, [], $headers)->assertStatus(422);

        $this->assertEquals(2, BomLine::firstOrFail()->quantity);
        $this->assertSame(1, BomLine::count());
    }

    public function test_only_bom_manage_can_write_and_production_view_can_read()
    {
        $variation = Variation::factory()->create();
        $sheet = $this->stock(0);
        $this->bom($variation->product, [[$sheet, 2]]);
        $url = "/api/v1/admin/products/{$variation->product_id}/bom";
        $payload = ['lines' => []];

        foreach (['Sales', 'Warehouse', 'Admin'] as $role) {
            $this->putJson($url, $payload, $this->header($role))->assertStatus(403);
        }
        $this->assertSame(1, BomLine::count());

        $this->getJson($url, $this->header('Sales'))->assertStatus(200);
        $this->getJson($url, $this->header('Technic'))->assertStatus(200);
        $this->getJson($url, $this->header('Warehouse'))->assertStatus(403);
        $this->getJson($url, $this->header('Admin'))->assertStatus(403);
    }

    public function test_old_route_creates_and_uses_default_designs()
    {
        $variation = Variation::factory()->create(['size' => '120']);
        $sheet = $this->stock(0);
        $url = "/api/v1/admin/products/{$variation->product_id}/bom";

        $this->putJson($url, ['lines' => [['stock_item_id' => $sheet->id, 'quantity' => 1]]], $this->header('Technic'))->assertStatus(200);
        $standard = \App\Models\ProductDesign::where('product_id', $variation->product_id)->sole();
        $this->assertSame(['استاندارد', true, null], [$standard->name, $standard->is_default, $variation->fresh()->design_id]);

        $this->putJson($url, ['variation_id' => $variation->id, 'lines' => [['stock_item_id' => $sheet->id, 'quantity' => 2]]], $this->header('Technic'))
            ->assertStatus(200)->assertJsonPath('source', 'variation');
        $own = $variation->fresh()->design;
        $this->assertFalse($own->is_default);
        $this->assertSame([1.0], $standard->bomLines()->pluck('quantity')->all());
    }
}
