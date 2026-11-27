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
        $part = ['name' => 'صفحه', 'length_mm' => 2000, 'width_mm' => 400, 'count' => 1, 'stock_item_id' => $this->body16()->id, 'edge_length' => 0, 'edge_width' => 0];
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

        $variation->update(['design_id' => $b->id]);
        $url = "/api/v1/admin/designs/{$b->id}";

        $this->putJson($url, ['name' => 'گردویی'], $headers)
            ->assertStatus(200)->assertJsonPath('data.variation_ids', [$variation->id]);
        $this->putJson($url, ['is_default' => true], $headers)->assertStatus(200);
        $this->assertFalse($a->fresh()->is_default);

        // A variation with no design of its own is built from the default; the old design field is ignored.
        $plain = Variation::factory()->create(['product_id' => $variation->product_id]);
        $this->putJson("/api/v1/admin/designs/{$a->id}", ['variation_id' => $plain->id], $headers)->assertStatus(200)
            ->assertJsonPath('data.variation_ids', []);
        $this->getJson($url, $headers)->assertJsonPath('data.variation_ids', [$variation->id, $plain->id]);
    }

    public function test_calculate_shows_unsaved_figures_and_saves_nothing()
    {
        $design = $this->damtv220On(ProductDesign::factory()->create());
        $hinge = $this->stock(0, ['code' => 'HNG-01']);
        $parts = $this->damtv220Parts();
        $parts[0]['count'] = 3; // two more 2000 × 400 tops: 2.761 + 1.6 = 4.361 m²
        $url = "/api/v1/admin/designs/{$design->id}/calculate";
        $body = ['parts' => $parts, 'lines' => [['stock_item_id' => $hinge->id, 'quantity' => 4]]];

        $r = $this->postJson($url, $body, $this->header('Technic'))->assertStatus(200);

        // 4.361 m² × 1.2 ÷ 6.6978 = 0.7813 → 0.79
        $r->assertJsonPath('summary.0.sheets', 0.79)->assertJsonPath('warnings', []);
        $lines = collect($r->json('lines'))->mapWithKeys(fn ($l) => [$l['stock_item']['code'] => [$l['quantity'], $l['calculated']]])->sortKeys()->all();
        $this->assertSame(['HDF-3-WH' => [0.07, true], 'HNG-01' => [4, false], 'MDF-16-WAL' => [0.79, true]], $lines);
        $this->assertStringNotContainsString('cost', json_encode($r->json()));
        $this->assertSame(1, $design->parts()->where('position', 0)->value('count'));
        $this->assertSame(0, $design->bomLines()->count());

        $this->postJson($url, ['parts' => $parts, 'lines' => [['stock_item_id' => $this->body16()->id, 'quantity' => 1]]], $this->header('Technic'))
            ->assertStatus(422)->assertJsonValidationErrors('lines');
    }

    public function test_calculate_gives_costs_only_with_costs_view()
    {
        $design = ProductDesign::factory()->create();
        app(\App\Services\Inventory\InventoryService::class)->receive($this->body16(), 10, 1_100_000, null);
        $adder = \App\Models\CostAdder::create(['name' => 'پرس وکیوم', 'group' => 'vacuum', 'basis' => 'service', 'unit' => 'square_metre', 'unit_cost' => 900_000]);
        $parts = array_values(array_filter($this->damtv220Parts(), fn ($p) => $p['stock_item_id'] === $this->body16()->id));
        $body = ['parts' => $parts, 'lines' => [], 'adders' => [['cost_adder_id' => $adder->id, 'quantity' => 1.5]]];

        $r = $this->postJson("/api/v1/admin/designs/{$design->id}/calculate", $body, $this->header('SuperAdmin'))->assertStatus(200);

        // 0.50 sheet × 1,100,000 = 550,000; vacuum 1.5 m² × 900,000 = 1,350,000
        $r->assertJsonPath('lines.0.cost', 550000)
            ->assertJsonPath('costs.material_cost', 550000)
            ->assertJsonPath('costs.adders_cost', 1350000)
            ->assertJsonPath('costs.total', 1900000);
    }

    public function test_save_contents_is_all_or_nothing()
    {
        $design = ProductDesign::factory()->create();
        $hinge = $this->stock(0, ['code' => 'HNG-01']);
        $adder = \App\Models\CostAdder::create(['name' => 'پرس وکیوم', 'group' => 'vacuum', 'basis' => 'service', 'unit' => 'square_metre', 'unit_cost' => 900_000]);
        $url = "/api/v1/admin/designs/{$design->id}/contents";
        $body = ['parts' => $this->damtv220Parts(), 'lines' => [['stock_item_id' => $hinge->id, 'quantity' => 4]], 'adders' => [['cost_adder_id' => $adder->id, 'quantity' => 1.8]]];

        $bad = $body;
        $bad['parts'][0]['edge_length'] = 3;
        $this->putJson($url, $bad, $this->header('Technic'))->assertStatus(422)->assertJsonValidationErrors('parts.0.edge_length');
        $this->assertSame([0, 0, 0], [$design->parts()->count(), $design->bomLines()->count(), $design->adders()->count()]);

        $this->putJson($url, $body, $this->header('Technic'))->assertStatus(200)->assertJsonCount(7, 'data.data');
        $this->assertSame(7, $design->parts()->count());
        $this->assertEqualsCanonicalizing(['HDF-3-WH', 'HNG-01', 'MDF-16-WAL'], $design->bomLines()->with('stockItem')->get()->pluck('stockItem.code')->all());
        $this->assertSame([[$adder->id, 1.8]], $design->adders()->get()->map(fn ($a) => [$a->cost_adder_id, (float) $a->quantity])->all());

        $this->putJson($url, $body, $this->header('Sales'))->assertStatus(403);
    }

    public function test_estimated_cost_is_listed_only_with_costs_view()
    {
        $design = ProductDesign::factory()->create();
        app(\App\Services\Inventory\InventoryService::class)->receive($this->body16(), 10, 1_100_000, null);
        $hinge = $this->stock(10, ['code' => 'HNG-01'], 50_000);
        \App\Models\BomLine::create(['design_id' => $design->id, 'stock_item_id' => $this->body16()->id, 'quantity' => 0.5]);
        \App\Models\BomLine::create(['design_id' => $design->id, 'stock_item_id' => $hinge->id, 'quantity' => 4]);
        $url = "/api/v1/admin/products/{$design->product_id}/designs";

        $this->getJson($url, $this->header('SuperAdmin'))->assertJsonPath('data.0.estimated_cost', 750000);
        $r = $this->getJson($url, $this->withPermission('orders.create'))->assertStatus(200);
        $this->assertArrayNotHasKey('estimated_cost', $r->json('data.0'));
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
            ['put', "/api/v1/admin/designs/{$design->id}/contents", ['parts' => [], 'lines' => []]],
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
            $body = $this->getJson($url, $headers)->assertStatus(200)->json();
            // The list carries the estimated cost for costs.view (see test_estimated_cost_is_listed_only_with_costs_view).
            if (str_ends_with($url, '/designs')) {
                $body['data'] = array_map(fn ($row) => array_diff_key($row, ['estimated_cost' => 1]), $body['data']);
            }
            $json = json_encode($body);
            $this->assertStringNotContainsString('cost', $json, $url);
            $this->assertStringNotContainsString('1100000', $json, $url);
        }
    }
}
