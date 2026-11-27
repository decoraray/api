<?php

namespace Tests\Feature\Variations;

use App\Models\Product;
use App\Models\Variation;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class VariationCostApiTest extends VariationTestCase
{
    private function withPermissions(string ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $this->header($user);
    }

    /** One design shared by a walnut-sheet and a high-gloss variation. */
    private function shared(): array
    {
        $design = $this->unit();
        $plain = $this->variationOf($design, $this->material('ام‌دی‌اف', $this->body16()));
        $gloss = $this->variationOf($design, $this->material('هایگلاس', $this->gloss()), ['price' => 9_000_000]);

        return [$design, $plain, $gloss];
    }

    public function test_one_design_costs_differently_per_material()
    {
        [$design, $plain, $gloss] = $this->shared();
        $owner = $this->header('SuperAdmin');

        $a = $this->getJson("/api/v1/admin/variations/{$plain->id}/cost", $owner)->assertStatus(200)
            ->assertJsonPath('data.design.id', $design->id)
            ->assertJsonPath('data.price', 7_000_000)
            ->assertJsonPath('data.incomplete', false)->json('data');
        $b = $this->getJson("/api/v1/admin/variations/{$gloss->id}/cost", $owner)->assertStatus(200)->json('data');

        // 0.50 walnut sheet × 6,000,000 + 0.07 back × 3,000,000.
        $this->assertSame(3_210_000, $a['estimated_cost']);
        $this->assertSame(6_750_000, $a['suggested_price']);
        $this->assertGreaterThan($a['estimated_cost'], $b['estimated_cost']);
        $this->assertGreaterThan($a['suggested_price'], $b['suggested_price']);
        $this->assertSame('HIGLOSS16', $b['sheets'][0]['to']['code']);
        $this->assertSame([], $a['sheets']);

        $all = $this->getJson("/api/v1/admin/products/{$design->product_id}/variation-costs", $owner)->assertStatus(200)->json('data');
        $this->assertSame([$a, $b], $all);
    }

    public function test_nothing_to_build_from_and_missing_costs()
    {
        $loose = Variation::factory()->create(['product_id' => Product::factory()->create()->id, 'price' => 100]);
        $this->getJson("/api/v1/admin/variations/{$loose->id}/cost", $this->header('SuperAdmin'))->assertStatus(200)
            ->assertJsonPath('data.design', null)->assertJsonPath('data.estimated_cost', null)->assertJsonPath('data.suggested_price', null);

        $design = $this->unit();
        $variation = $this->variationOf($design, $this->material('تازه', $this->stock(0, ['code' => 'NEW16', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16])));
        $this->getJson("/api/v1/admin/variations/{$variation->id}/cost", $this->header('SuperAdmin'))->assertStatus(200)
            ->assertJsonPath('data.incomplete', true);
    }

    public function test_costs_need_costs_view()
    {
        [, $plain] = $this->shared();
        $sales = $this->withPermissions('prices.view', 'catalog.view', 'catalog.edit');

        $this->getJson("/api/v1/admin/variations/{$plain->id}/cost", $sales)->assertStatus(403);
        $this->getJson("/api/v1/admin/products/{$plain->product_id}/variation-costs", $sales)->assertStatus(403);
        $this->postJson("/api/v1/admin/variations/{$plain->id}/apply-suggested-price", [], $sales)->assertStatus(403);
        $this->postJson("/api/v1/admin/variations/{$plain->id}/apply-suggested-price", [], $this->withPermissions('costs.view'))->assertStatus(403);
        $this->assertEquals(7_000_000, $plain->fresh()->price);
    }

    public function test_applying_the_suggestion_sets_the_price_once_and_audits_it()
    {
        [, $plain] = $this->shared();
        $owner = $this->header('SuperAdmin');

        $this->postJson("/api/v1/admin/variations/{$plain->id}/apply-suggested-price", [], $owner)->assertStatus(200)
            ->assertJsonPath('data.price', 6_750_000);
        $this->assertEquals(6_750_000, $plain->fresh()->price);
        $log = Activity::where('subject_type', Variation::class)->where('subject_id', $plain->id)->latest('id')->first();
        $this->assertEquals([7_000_000, 6_750_000], [$log->properties['old']['price'], $log->properties['attributes']['price']]);

        // A dearer sheet raises the suggestion only.
        $this->price($this->body16(), 9_000_000);
        $this->getJson("/api/v1/admin/variations/{$plain->id}/cost", $owner)->assertJsonPath('data.price', 6_750_000);
        $this->assertEquals(6_750_000, $plain->fresh()->price);
    }

    public function test_applying_without_a_suggestion_is_refused()
    {
        $loose = Variation::factory()->create(['product_id' => Product::factory()->create()->id, 'price' => 100]);

        $this->postJson("/api/v1/admin/variations/{$loose->id}/apply-suggested-price", [], $this->header('SuperAdmin'))
            ->assertStatus(422)->assertJsonValidationErrors('price');
        $this->assertEquals(100, $loose->fresh()->price);
    }

    public function test_the_design_page_lists_each_variation_in_its_material()
    {
        [$design, $plain, $gloss] = $this->shared();

        $r = $this->postJson("/api/v1/admin/designs/{$design->id}/calculate", ['parts' => [], 'lines' => [], 'adders' => []], $this->header('SuperAdmin'))
            ->assertStatus(200);

        $rows = collect($r->json('costs.variations'))->keyBy('variation.id');
        $this->assertSame([$plain->id, $gloss->id], $rows->keys()->all());
        $this->assertSame('هایگلاس', $rows[$gloss->id]['material']['name']);
        $this->assertGreaterThan($rows[$plain->id]['estimated_cost'], $rows[$gloss->id]['estimated_cost']);
        $this->assertNotNull($rows[$gloss->id]['margin']);
    }

    public function test_the_report_costs_each_variation_in_its_material()
    {
        [$design, $plain, $gloss] = $this->shared();
        // No design of its own: built from the product default.
        $fallback = Variation::factory()->create(['product_id' => $design->product_id, 'price' => 1_000_000, 'active' => true]);

        $rows = collect($this->getJson('/api/v1/admin/reports/suggested-prices', $this->header('SuperAdmin'))->assertStatus(200)->json('data'))
            ->keyBy('variation.id');

        $this->assertSame([$design->id, $design->id, $design->id], [$rows[$plain->id]['design']['id'], $rows[$gloss->id]['design']['id'], $rows[$fallback->id]['design']['id']]);
        $this->assertSame('هایگلاس', $rows[$gloss->id]['material']['name']);
        $this->assertGreaterThan($rows[$plain->id]['estimated_cost'], $rows[$gloss->id]['estimated_cost']);
        $this->assertSame($rows[$plain->id]['estimated_cost'], $rows[$fallback->id]['estimated_cost']);
    }
}
