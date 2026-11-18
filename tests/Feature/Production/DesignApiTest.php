<?php

namespace Tests\Feature\Production;

use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\User;
use App\Models\Variation;
use Spatie\Activitylog\Models\Activity;

class DesignApiTest extends CuttingTestCase
{
    private function withPermission(string $permission): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $this->header($user);
    }

    public function test_two_sizes_of_one_product()
    {
        $product = Product::factory()->create(['title' => 'DAMTV220', 'dimensions' => '220*40*40']);
        $headers = $this->header('Technic');

        $big = $this->postJson("/api/v1/admin/products/{$product->id}/designs", ['name' => '۲۲۰ سانتی'], $headers)
            ->assertStatus(201)->assertJsonPath('data.is_default', true)->json('data.id');
        $this->putJson("/api/v1/admin/designs/{$big}/cutting-list", ['parts' => $this->damtv220Parts()], $headers)->assertStatus(200);

        $small = $this->postJson("/api/v1/admin/products/{$product->id}/designs", ['name' => '۱۸۰ سانتی', 'dimensions' => '180*40*40', 'copy_from_design_id' => $big], $headers)
            ->assertStatus(201)->assertJsonPath('data.is_default', false)->assertJsonPath('data.parts_count', 7)->json('data.id');
        $parts = $this->damtv220Parts();
        $parts[0]['length_mm'] = 1800;
        $parts[1]['length_mm'] = 1767;
        $this->putJson("/api/v1/admin/designs/{$small}/cutting-list", ['parts' => $parts], $headers)
            ->assertStatus(200)->assertJsonPath('data.0.length_mm', 1800)->assertJsonPath('warnings', []);

        $this->getJson("/api/v1/admin/products/{$product->id}/designs", $headers)
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', '۲۲۰ سانتی')
            ->assertJsonPath('data.1.dimensions', '180*40*40');
        $this->getJson("/api/v1/admin/designs/{$big}/cutting-list", $headers)->assertJsonPath('data.0.length_mm', 2000);

        $this->postJson("/api/v1/admin/products/{$product->id}/designs", ['name' => '۱۸۰ سانتی'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertSame(2, ProductDesign::count());
    }

    public function test_fit_warning_uses_the_design_dimensions_then_the_products()
    {
        $product = Product::factory()->create(['dimensions' => '220*40*40']);
        $design = ProductDesign::factory()->create(['product_id' => $product->id, 'dimensions' => '180*40*40']);
        $part = ['name' => 'صفحه', 'length_mm' => 2000, 'width_mm' => 400, 'count' => 1, 'panel_material_id' => $this->body16()->id, 'edge_length' => 0, 'edge_width' => 0];
        $headers = $this->header('Technic');

        $this->putJson("/api/v1/admin/designs/{$design->id}/cutting-list", ['parts' => [$part]], $headers)
            ->assertStatus(200)->assertJsonCount(1, 'warnings');

        $design->update(['dimensions' => null]);
        $this->getJson("/api/v1/admin/designs/{$design->id}/cutting-list", $headers)->assertJsonCount(0, 'warnings');
    }

    public function test_variation_default_and_default_switch()
    {
        $variation = Variation::factory()->create();
        $a = ProductDesign::factory()->create(['product_id' => $variation->product_id, 'is_default' => true]);
        $b = ProductDesign::factory()->create(['product_id' => $variation->product_id, 'name' => 'گردویی']);
        $headers = $this->header('Technic');

        $this->putJson("/api/v1/admin/designs/{$b->id}", ['variation_id' => $variation->id], $headers)
            ->assertStatus(200)->assertJsonPath('data.variation_id', $variation->id);
        $this->putJson("/api/v1/admin/designs/{$b->id}", ['is_default' => true], $headers)->assertStatus(200);
        $this->assertFalse($a->fresh()->is_default);

        $this->putJson("/api/v1/admin/designs/{$a->id}", ['variation_id' => Variation::factory()->create()->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('variation_id');
    }

    public function test_saved_mapping_is_reused_and_a_missing_one_names_the_material()
    {
        $design = $this->damtv220On(ProductDesign::factory()->create());
        $walnut = $this->stock(0, ['code' => 'MDF-16-WAL', 'kind' => 'mdf_sheet', 'unit' => 'sheet']);
        $band = $this->stock(0, ['code' => 'EB-WAL-2', 'kind' => 'edge_band', 'unit' => 'meter']);
        $hdf = $this->stock(0, ['code' => 'HDF-3-WH', 'kind' => 'mdf_sheet', 'unit' => 'sheet']);
        $headers = $this->header('Technic');
        $url = "/api/v1/admin/designs/{$design->id}/bom-lines";

        $this->postJson($url, [], $headers)->assertStatus(422)->assertJsonValidationErrors('mapping');
        $this->postJson($url, ['mapping' => [['panel_material_id' => $this->body16()->id, 'sheet_stock_item_id' => $walnut->id]]], $headers)
            ->assertStatus(422)->assertJsonFragment(['mapping' => ['قلم ورق برای «پشت‌بند ۳ میل» انتخاب نشده است.']]);

        $this->postJson($url, ['mapping' => [
            ['panel_material_id' => $this->body16()->id, 'sheet_stock_item_id' => $walnut->id, 'edge_band_stock_item_id' => $band->id],
            ['panel_material_id' => $this->back3()->id, 'sheet_stock_item_id' => $hdf->id],
        ]], $headers)->assertStatus(200)->assertJsonPath('data.0.quantity', 0.5);

        // Recalculate after a change, without sending the mapping again.
        $r = $this->postJson($url, [], $headers)->assertStatus(200);
        $this->assertSame([['MDF-16-WAL', 0.5], ['EB-WAL-2', 2.6], ['HDF-3-WH', 0.07]],
            array_map(fn ($l) => [$l['stock_item']['code'], $l['quantity']], $r->json('data')));
        $r->assertJsonPath('panel_map.0.sheet_stock_item.code', 'MDF-16-WAL');
        $this->getJson("/api/v1/admin/designs/{$design->id}", $headers)->assertJsonPath('data.panel_map.1.sheet_stock_item.code', 'HDF-3-WH');
        $this->assertSame(0, $design->bomLines()->count());

        $this->postJson($url, ['mapping' => [['panel_material_id' => $this->body16()->id, 'sheet_stock_item_id' => $band->id]]], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('mapping.0.sheet_stock_item_id');
    }

    public function test_bom_and_delete()
    {
        $design = ProductDesign::factory()->create();
        $sheet = $this->stock(0);
        $headers = $this->header('Technic');

        $this->putJson("/api/v1/admin/designs/{$design->id}/bom", ['lines' => [['stock_item_id' => $sheet->id, 'quantity' => 1.5]]], $headers)
            ->assertStatus(200)->assertJsonPath('data.0.quantity', 1.5);
        $this->putJson("/api/v1/admin/designs/{$design->id}/bom", ['lines' => [['stock_item_id' => $sheet->id, 'quantity' => 1], ['stock_item_id' => $sheet->id, 'quantity' => 2]]], $headers)
            ->assertStatus(422);
        $this->getJson("/api/v1/admin/designs/{$design->id}/bom", $headers)->assertJsonCount(1, 'data');

        Item::create(['title' => 'x', 'slug' => 'x', 'price' => 1, 'quantity' => 1, 'order_id' => Order::factory()->create()->id, 'design_id' => $design->id]);
        $this->deleteJson("/api/v1/admin/designs/{$design->id}", [], $headers)->assertStatus(422)->assertJsonValidationErrors('design');

        $this->putJson("/api/v1/admin/designs/{$design->id}", ['active' => false], $headers)->assertStatus(200)->assertJsonPath('data.active', false);
        $this->getJson("/api/v1/admin/products/{$design->product_id}/designs", $headers)->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/admin/products/{$design->product_id}/designs?include_inactive=1", $headers)->assertJsonCount(1, 'data');

        $unused = ProductDesign::factory()->create();
        $this->deleteJson("/api/v1/admin/designs/{$unused->id}", [], $headers)->assertStatus(200);
        $this->assertNull($unused->fresh());
    }

    public function test_permissions()
    {
        $design = ProductDesign::factory()->create();
        $product = $design->product_id;
        $sales = $this->header('Sales');
        $writes = [
            ['post', "/api/v1/admin/products/{$product}/designs", ['name' => 'x']],
            ['put', "/api/v1/admin/designs/{$design->id}", ['name' => 'y']],
            ['delete', "/api/v1/admin/designs/{$design->id}", []],
            ['put', "/api/v1/admin/designs/{$design->id}/cutting-list", ['parts' => []]],
            ['put', "/api/v1/admin/designs/{$design->id}/bom", ['lines' => []]],
            ['post', "/api/v1/admin/designs/{$design->id}/bom-lines", []],
        ];
        foreach ($writes as [$method, $url, $body]) {
            $this->json($method, $url, $body, $sales)->assertStatus(403);
        }

        foreach (['orders.create', 'quotes.manage', 'production.view', 'bom.manage'] as $permission) {
            $headers = $this->withPermission($permission);
            $this->getJson("/api/v1/admin/products/{$product}/designs", $headers)->assertStatus(200);
            $this->getJson("/api/v1/admin/designs/{$design->id}/sheet", $headers)->assertStatus(200);
        }
        $this->getJson("/api/v1/admin/designs/{$design->id}", $this->withPermission('orders.create'))->assertStatus(403);
        $this->getJson("/api/v1/admin/products/{$product}/designs", $this->header('Warehouse'))->assertStatus(403);
    }

    public function test_changes_are_audited_and_carry_no_cost()
    {
        $product = $this->damtv220();
        $design = $this->design($product);
        $headers = $this->header('SuperAdmin');
        $sheet = $this->stock(5, [], 1100000);

        $this->putJson("/api/v1/admin/designs/{$design->id}/bom", ['lines' => [['stock_item_id' => $sheet->id, 'quantity' => 1]]], $headers)->assertStatus(200);
        $this->putJson("/api/v1/admin/designs/{$design->id}", ['name' => 'جدید'], $headers)->assertStatus(200);

        $this->assertTrue(Activity::where('description', 'bom-updated')->where('subject_id', $design->id)->exists());
        $this->assertTrue(Activity::where('subject_type', ProductDesign::class)->where('description', 'updated')->exists());

        foreach (["/api/v1/admin/designs/{$design->id}", "/api/v1/admin/designs/{$design->id}/sheet?quantity=2", "/api/v1/admin/products/{$product->id}/designs"] as $url) {
            $json = json_encode($this->getJson($url, $headers)->assertStatus(200)->json());
            $this->assertStringNotContainsString('cost', $json, $url);
            $this->assertStringNotContainsString('1100000', $json, $url);
        }
    }
}
