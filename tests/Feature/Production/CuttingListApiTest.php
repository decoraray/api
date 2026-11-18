<?php

namespace Tests\Feature\Production;

use App\Models\PanelMaterial;
use App\Models\Product;
use App\Models\ProductPart;
use App\Models\Variation;

class CuttingListApiTest extends CuttingTestCase
{
    private function url(Product|int $product): string
    {
        $id = $product instanceof Product ? $product->id : $product;

        return "/api/v1/admin/products/{$id}/cutting-list";
    }

    public function test_saves_and_reads_the_damtv220_list_in_order()
    {
        $product = Product::factory()->create(['dimensions' => '200*40*40']);

        $this->putJson($this->url($product), ['parts' => $this->damtv220Parts()], $this->header('Technic'))
            ->assertStatus(200)
            ->assertJsonPath('source', 'product')
            ->assertJsonCount(7, 'data');

        $r = $this->getJson($this->url($product), $this->header('Sales'))->assertStatus(200);
        $this->assertSame(['صفحه رویی', 'کف', 'درب ها', 'طبقه', 'بغل ها', 'وادارها', 'پشت بند'], array_column($r->json('data'), 'name'));
        $r->assertJsonPath('data.2.length_mm', 283)
            ->assertJsonPath('data.2.width_mm', 380)
            ->assertJsonPath('data.2.count', 4)
            ->assertJsonPath('data.2.edge_length', 2)
            ->assertJsonPath('data.2.edge_width', 0)
            ->assertJsonPath('data.2.grain_locked', true)
            ->assertJsonPath('data.1.ops', ['edge_fold', 'cnc'])
            ->assertJsonPath('data.0.note', 'لبه خم ۱۰ سانت')
            ->assertJsonPath('data.6.panel_material.name', 'پشت‌بند ۳ میل')
            ->assertJsonPath('summary.0.area_m2', 2.761)
            ->assertJsonPath('summary.0.sheets', 0.5)
            ->assertJsonPath('summary.0.edge_m', 2.6)
            ->assertJsonPath('summary.1.sheets', 0.07)
            ->assertJsonPath('warnings', []);
        $this->assertStringNotContainsString('cost', json_encode($r->json()));
    }

    public function test_variation_override_fallback_and_clearing()
    {
        $walnut = Variation::factory()->create();
        $other = Variation::factory()->create(['product_id' => $walnut->product_id]);
        $headers = $this->header('Technic');
        $part = fn ($name) => ['name' => $name, 'length_mm' => 500, 'width_mm' => 380, 'count' => 1, 'panel_material_id' => $this->body16()->id, 'edge_length' => 0, 'edge_width' => 0];

        $this->putJson($this->url($walnut->product_id), ['parts' => [$part('مشترک')]], $headers)->assertStatus(200);
        $this->putJson($this->url($walnut->product_id), ['variation_id' => $walnut->id, 'parts' => [$part('گردویی')]], $headers)
            ->assertStatus(200)->assertJsonPath('source', 'variation');

        $this->getJson($this->url($walnut->product_id) . "?variation_id={$walnut->id}", $headers)
            ->assertJsonPath('data.0.name', 'گردویی')->assertJsonPath('source', 'variation');
        $this->getJson($this->url($walnut->product_id) . "?variation_id={$other->id}", $headers)
            ->assertJsonPath('data.0.name', 'مشترک')->assertJsonPath('source', 'product');

        $this->putJson($this->url($walnut->product_id), ['variation_id' => $walnut->id, 'parts' => []], $headers)
            ->assertStatus(200)->assertJsonPath('source', 'product');
        $this->putJson($this->url($walnut->product_id), ['parts' => []], $headers)
            ->assertStatus(200)->assertJsonPath('source', 'none')->assertJsonCount(0, 'data');
        $this->assertSame(0, ProductPart::count());
    }

    public function test_invalid_parts_are_rejected_and_change_nothing()
    {
        $product = $this->damtv220();
        $foreign = Variation::factory()->create();
        $inactive = PanelMaterial::factory()->create(['active' => false]);
        $headers = $this->header('Technic');
        $good = ['name' => 'بغل', 'length_mm' => 500, 'width_mm' => 380, 'count' => 1, 'panel_material_id' => $this->body16()->id, 'edge_length' => 0, 'edge_width' => 0];

        $cases = [
            'parts.0.edge_length' => ['edge_length' => 3],
            'parts.0.edge_width' => ['edge_width' => -1],
            'parts.0.ops.0' => ['ops' => ['paint']],
            'parts.0.length_mm' => ['length_mm' => 5001],
            'parts.0.width_mm' => ['width_mm' => 0],
            'parts.0.count' => ['count' => 0],
            'parts.0.panel_material_id' => ['panel_material_id' => $inactive->id],
            'parts.0.name' => ['name' => ''],
        ];
        foreach ($cases as $field => $override) {
            $this->putJson($this->url($product), ['parts' => [array_merge($good, $override)]], $headers)
                ->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->putJson($this->url($product), ['variation_id' => $foreign->id, 'parts' => [$good]], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('variation_id');

        $this->assertSame(7, ProductPart::where('product_id', $product->id)->count());
    }

    public function test_fit_warning_does_not_block_saving()
    {
        $product = Product::factory()->create(['dimensions' => '200*40*40']);
        $part = ['name' => 'صفحه', 'length_mm' => 2100, 'width_mm' => 400, 'count' => 1, 'panel_material_id' => $this->body16()->id, 'edge_length' => 0, 'edge_width' => 0];

        $this->putJson($this->url($product), ['parts' => [$part]], $this->header('Technic'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'warnings')
            ->assertJsonPath('warnings.0.index', 0);
    }

    public function test_permissions()
    {
        $product = $this->damtv220();

        $this->getJson($this->url($product), $this->header('Sales'))->assertStatus(200);
        $this->getJson($this->url($product), $this->header('Warehouse'))->assertStatus(403);
        $this->putJson($this->url($product), ['parts' => []], $this->header('Sales'))->assertStatus(403);
        $this->assertSame(7, ProductPart::count());
    }

    public function test_update_is_logged()
    {
        $product = Product::factory()->create();

        $this->putJson($this->url($product), ['parts' => $this->damtv220Parts()], $this->header('Technic'))->assertStatus(200);

        $this->assertDatabaseHas('activity_log', ['description' => 'cutting-list-updated', 'subject_id' => $product->id]);
    }
}
