<?php

namespace Tests\Feature\Costing;

use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\StockItem;
use App\Models\User;
use App\Models\Variation;
use App\Services\Production\DesignEstimate;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Production\CuttingTestCase;

class SuggestedPriceApiTest extends CuttingTestCase
{
    private function withPermissions(string ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $this->header($user);
    }

    /** A hand line whose cost comes to the given amount: four hinges at a quarter each. */
    private function hinges(int $cost, array $attributes = []): StockItem
    {
        return $this->stock(10, $attributes + ['name' => 'لولا'], intdiv($cost, 4));
    }

    /** A product's variation with a price, whose default design costs the given amount. */
    private function defaulted(string $title, int $price, int $cost, array $product = []): ProductDesign
    {
        $p = Product::factory()->create($product + ['title' => $title, 'active' => true]);
        $v = Variation::factory()->create(['product_id' => $p->id, 'price' => $price, 'active' => true]);
        $design = ProductDesign::factory()->create(['product_id' => $p->id]);
        $v->update(['design_id' => $design->id]);
        if ($cost > 0) {
            $design->bomLines()->create(['stock_item_id' => $this->hinges($cost)->id, 'quantity' => 4]);
        }

        return $design;
    }

    public function test_settings_defaults_change_and_audit()
    {
        $url = '/api/v1/admin/costing/settings';
        $owner = $this->header('SuperAdmin');

        $this->getJson($url, $owner)->assertStatus(200)
            ->assertJsonPath('price_multiplier', 2.1)->assertJsonPath('price_rounding', 10000);

        $this->putJson($url, ['overhead_percent' => 5], $owner)->assertStatus(200);
        $this->putJson($url, ['price_multiplier' => 2.3], $owner)->assertStatus(200)
            ->assertJsonPath('price_multiplier', 2.3)->assertJsonPath('overhead_percent', 5);

        $this->putJson($url, ['price_multiplier' => 0.8], $owner)->assertStatus(422)->assertJsonValidationErrors('price_multiplier');
        $this->putJson($url, ['price_multiplier' => 2.345], $owner)->assertStatus(422);
        $this->putJson($url, ['price_rounding' => 3000], $owner)->assertStatus(422)->assertJsonValidationErrors('price_rounding');
        $this->getJson($url, $owner)->assertJsonPath('price_multiplier', 2.3)->assertJsonPath('price_rounding', 10000);

        $log = Activity::where('description', 'costing-price-multiplier')->sole();
        $this->assertEquals([2.1, 2.3], [$log->properties['old']['price_multiplier'], $log->properties['attributes']['price_multiplier']]);
        $this->assertSame(0, Activity::where('description', 'costing-price-rounding')->count());

        $viewer = $this->withPermissions('costs.view');
        $this->getJson($url, $viewer)->assertStatus(200)->assertJsonPath('price_multiplier', 2.3);
        $this->putJson($url, ['price_multiplier' => 2.5], $viewer)->assertStatus(403);
    }

    public function test_calculate_gives_the_live_suggestion_and_the_variations_built_from_the_design()
    {
        $design = $this->defaulted('DAMTV220', 7_000_000, 3_500_000);
        $hinge = $design->bomLines()->first()->stockItem;
        $body = ['parts' => [], 'lines' => [['stock_item_id' => $hinge->id, 'quantity' => 4]], 'adders' => []];

        $this->postJson("/api/v1/admin/designs/{$design->id}/calculate", $body, $this->header('SuperAdmin'))->assertStatus(200)
            ->assertJsonPath('costs.total', 3_500_000)
            ->assertJsonPath('costs.suggested_price', 7_350_000)
            ->assertJsonPath('costs.price_multiplier', 2.1)
            ->assertJsonPath('costs.incomplete', false)
            ->assertJsonPath('costs.variations.0.price', 7_000_000)
            ->assertJsonPath('costs.variations.0.suggested_price', 7_350_000)
            ->assertJsonPath('costs.variations.0.margin', 50)
            ->assertJsonCount(1, 'costs.variations');

        // Nothing was saved: the design still has its one line.
        $this->assertSame(1, $design->bomLines()->count());
    }

    public function test_calculate_without_a_variation_or_a_price()
    {
        $hinge = $this->hinges(1_000_000);
        $body = ['parts' => [], 'lines' => [['stock_item_id' => $hinge->id, 'quantity' => 4]], 'adders' => []];

        $plain = ProductDesign::factory()->create();
        $r = $this->postJson("/api/v1/admin/designs/{$plain->id}/calculate", $body, $this->header('SuperAdmin'))->assertStatus(200)
            ->assertJsonPath('costs.suggested_price', 2_100_000);
        $this->assertSame([], $r->json('costs.variations'));

        $unpriced = $this->defaulted('UNPRICED', 0, 1_000_000);
        $this->postJson("/api/v1/admin/designs/{$unpriced->id}/calculate", $body, $this->header('SuperAdmin'))->assertStatus(200)
            ->assertJsonPath('costs.variations.0.price', null)
            ->assertJsonPath('costs.variations.0.margin', null);
    }

    public function test_an_item_never_received_marks_the_costs_incomplete()
    {
        $design = ProductDesign::factory()->create();
        $hinge = $this->hinges(1_000_000);
        $rail = $this->stock(0, ['name' => 'ریل کشو']);
        $body = ['parts' => [], 'lines' => [['stock_item_id' => $hinge->id, 'quantity' => 4], ['stock_item_id' => $rail->id, 'quantity' => 2]], 'adders' => []];

        $this->postJson("/api/v1/admin/designs/{$design->id}/calculate", $body, $this->header('SuperAdmin'))->assertStatus(200)
            ->assertJsonPath('costs.suggested_price', 2_100_000)
            ->assertJsonPath('costs.incomplete', true)
            ->assertJsonPath('costs.missing', [['id' => $rail->id, 'name' => 'ریل کشو']]);

        $design->bomLines()->create(['stock_item_id' => $rail->id, 'quantity' => 2]);
        $this->assertSame([['id' => $rail->id, 'name' => 'ریل کشو']], app(DesignEstimate::class)->designCosts($design)['missing']);
    }

    public function test_designs_index_gives_the_suggestion_only_with_costs_view()
    {
        $design = $this->defaulted('DAMTV220', 7_000_000, 3_500_000);
        $url = "/api/v1/admin/products/{$design->product_id}/designs";

        $this->getJson($url, $this->header('SuperAdmin'))->assertStatus(200)
            ->assertJsonPath('data.0.estimated_cost', 3_500_000)
            ->assertJsonPath('data.0.suggested_price', 7_350_000)
            ->assertJsonPath('data.0.incomplete', false);

        $sales = $this->withPermissions('production.view', 'prices.view');
        $row = $this->getJson($url, $sales)->assertStatus(200)->json('data.0');
        foreach (['estimated_cost', 'suggested_price', 'incomplete'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }

        $r = $this->postJson("/api/v1/admin/designs/{$design->id}/calculate", ['parts' => [], 'lines' => [], 'adders' => []], $this->withPermissions('production.view', 'prices.view'))->assertStatus(200);
        $this->assertArrayNotHasKey('costs', $r->json());
        $this->assertStringNotContainsString('suggested_price', $r->getContent());
        $this->assertStringNotContainsString('margin', $r->getContent());
    }

    public function test_report_lists_defaults_filters_below_and_sorts_by_gap()
    {
        $below = $this->defaulted('DAMTV220', 7_000_000, 3_500_000);   // suggests 7,350,000: gap −350,000
        $this->defaulted('SHELF90', 9_000_000, 3_800_000);             // suggests 7,980,000: gap 1,020,000
        $this->defaulted('DESK120', 5_000_000, 2_800_000);             // suggests 5,880,000: gap −880,000
        $this->defaulted('OLDCAB', 1_000_000, 3_000_000, ['active' => false]);
        $orphan = Variation::factory()->create(['product_id' => Product::factory()->create(['title' => 'NODESIGN'])->id, 'active' => true]);

        $url = '/api/v1/admin/reports/suggested-prices';
        $r = $this->getJson($url, $this->withPermissions('costs.view'))->assertStatus(200)
            ->assertJsonPath('totals', ['rows' => 3, 'below' => 2])
            ->assertJsonPath('meta.total', 3);
        $this->assertSame(['DESK120', 'DAMTV220', 'SHELF90'], array_column(array_column($r->json('data'), 'product'), 'name'));
        $this->assertSame([-880_000, -350_000, 1_020_000], array_column($r->json('data'), 'gap'));
        $this->assertNotContains($orphan->id, array_column(array_column($r->json('data'), 'variation'), 'id'));

        $this->getJson("{$url}?below=1", $this->header('SuperAdmin'))->assertJsonCount(2, 'data');
        $this->getJson("{$url}?below=1&q=DAMTV", $this->header('SuperAdmin'))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.design.id', $below->id)
            ->assertJsonPath('data.0.price', 7_000_000)
            ->assertJsonPath('data.0.estimated_cost', 3_500_000)
            ->assertJsonPath('data.0.suggested_price', 7_350_000)
            ->assertJsonPath('data.0.gap', -350_000)
            ->assertJsonPath('data.0.margin', 50)
            ->assertJsonPath('data.0.incomplete', false);
        $this->getJson("{$url}?per_page=2&page=2", $this->header('SuperAdmin'))->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
    }

    public function test_report_rows_without_a_price_or_a_cost_come_last()
    {
        $this->defaulted('UNPRICED', 0, 1_000_000);
        $this->defaulted('EMPTY', 5_000_000, 0);
        $this->defaulted('DAMTV220', 7_000_000, 3_500_000);

        $r = $this->getJson('/api/v1/admin/reports/suggested-prices', $this->header('SuperAdmin'))->assertStatus(200);
        $this->assertSame('DAMTV220', $r->json('data.0.product.name'));
        $this->assertEqualsCanonicalizing([[null, null, 2_100_000], [null, null, null]], array_map(fn ($row) => [$row['gap'], $row['margin'] ?? null, $row['suggested_price']], array_slice($r->json('data'), 1)));
    }

    public function test_report_needs_costs_view()
    {
        $this->getJson('/api/v1/admin/reports/suggested-prices', $this->withPermissions('prices.view', 'finance.view'))->assertStatus(403);
        $this->getJson('/api/v1/admin/reports/suggested-prices')->assertStatus(401);
    }
}
