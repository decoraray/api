<?php

namespace Tests\Feature\Production;

use App\Enums\OrderStatus;
use App\Models\Item;
use App\Models\Order;
use App\Models\ProductDesign;
use App\Models\Variation;
use App\Services\Production\DesignService;
use App\Services\Production\Drafts\DraftSeeder;

class DesignDraftApiTest extends DraftTestCase
{
    private const COST_KEYS = ['average_cost', 'cost', 'price', 'value', 'unit_cost', 'total_cost'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDrafts();
    }

    /** Two cabinets (one with a guessed reading, one labelled) and a TV unit, seeded as drafts. */
    private function seeded(): array
    {
        $guessed = $this->productIn($this->cabinets, '80*180*40', ['title' => 'کمد آبی DAKL01']);
        $labelled = $this->productIn($this->cabinets, 'طول 100 عمق 50 ارتفاع 200', ['title' => 'کمد سفید DAKL02']);
        $tv = $this->productIn($this->tvUnits, '160*50*40', ['title' => 'میز تی‌وی DAMTV01']);
        app(DraftSeeder::class)->run();

        return [
            ProductDesign::where('product_id', $guessed->id)->sole(),
            ProductDesign::where('product_id', $labelled->id)->sole(),
            ProductDesign::where('product_id', $tv->id)->sole(),
        ];
    }

    private function ids($response): array
    {
        return collect($response->json('data'))->pluck('id')->sort()->values()->all();
    }

    private function assertNoCost(array $json): void
    {
        array_walk_recursive($json, function ($value, $key) {
            $this->assertNotContains($key, self::COST_KEYS, "cost field «{$key}» in response");
        });
    }

    // --- review queue -------------------------------------------------------------

    public function test_review_lists_drafts_with_filters()
    {
        [$guessed, $labelled, $tv] = $this->seeded();
        $technic = $this->header('Technic');

        $all = $this->getJson('/api/v1/admin/designs/review', $technic)->assertStatus(200);
        $this->assertSame(3, $all->json('total'));
        $row = collect($all->json('data'))->firstWhere('id', $guessed->id);
        $this->assertSame(['id' => $guessed->product_id, 'title' => 'کمد آبی DAKL01', 'dimensions' => '80*180*40'], $row['product']);
        $this->assertSame('cabinet', $row['draft']['template']);
        $this->assertTrue($row['draft']['guessed']);
        $this->assertFalse($row['draft']['edited']);
        $this->assertTrue($row['draft']['pending']);
        $this->assertGreaterThan(0, $row['parts_count']);
        $this->assertSame([$this->cabinets->id, $this->tvUnits->id], collect($all->json('categories'))->pluck('id')->sort()->values()->all());

        $this->assertSame([$guessed->id, $tv->id], $this->ids($this->getJson('/api/v1/admin/designs/review?guessed=1', $technic)));
        $this->assertSame([$labelled->id], $this->ids($this->getJson('/api/v1/admin/designs/review?guessed=0', $technic)));
        $this->assertSame([$tv->id], $this->ids($this->getJson('/api/v1/admin/designs/review?template=low_unit', $technic)));
        $this->assertSame([$guessed->id, $labelled->id], $this->ids($this->getJson("/api/v1/admin/designs/review?category_id={$this->cabinets->id}", $technic)));
        $this->assertSame([$labelled->id], $this->ids($this->getJson('/api/v1/admin/designs/review?search=DAKL02', $technic)));
        $this->assertSame([$tv->id], $this->ids($this->getJson('/api/v1/admin/designs/review?search=' . urlencode('تی‌وی'), $technic)));

        $tv->parts()->first()->update(['count' => 9]);
        $this->assertSame([$tv->id], $this->ids($this->getJson('/api/v1/admin/designs/review?edited=1', $technic)));
        $this->assertSame([$guessed->id, $labelled->id], $this->ids($this->getJson('/api/v1/admin/designs/review?edited=0', $technic)));
    }

    public function test_approved_and_hand_written_designs_are_not_in_the_queue_and_most_ordered_come_first()
    {
        [$guessed, $labelled, $tv] = $this->seeded();
        app(DesignService::class)->setApproved($labelled, true, null);
        $this->damtv220();

        $order = Order::factory()->create(['status' => OrderStatus::CONFIRMED->value]);
        Item::create(['title' => 'x', 'slug' => 'x', 'price' => 1, 'quantity' => 1, 'order_id' => $order->id, 'product_id' => $tv->product_id]);

        $response = $this->getJson('/api/v1/admin/designs/review', $this->header('Technic'));
        $this->assertSame([$tv->id, $guessed->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_review_and_templates_permissions()
    {
        $this->seeded();
        // production.view (Sales has it) or bom.manage.
        foreach (['Technic', 'Sales', 'SuperAdmin'] as $role) {
            $this->getJson('/api/v1/admin/designs/review', $this->header($role))->assertStatus(200);
            $this->getJson('/api/v1/admin/design-templates', $this->header($role))->assertStatus(200);
        }
        foreach (['Admin', 'Accounting'] as $role) {
            $this->getJson('/api/v1/admin/designs/review', $this->header($role))->assertStatus(403);
            $this->getJson('/api/v1/admin/design-templates', $this->header($role))->assertStatus(403);
        }
    }

    public function test_templates_list()
    {
        $response = $this->getJson('/api/v1/admin/design-templates', $this->header('Technic'))->assertStatus(200);

        $cabinet = collect($response->json('data'))->firstWhere('key', 'cabinet');
        $this->assertSame('tall', $cabinet['axis_rule']);
        $this->assertContains('shelves', array_column($cabinet['params'], 'key'));
    }

    // --- regenerate ----------------------------------------------------------------

    public function test_regenerate_adds_drawers()
    {
        [, , $tv] = $this->seeded();

        $response = $this->postJson("/api/v1/admin/designs/{$tv->id}/regenerate", ['params' => ['drawers' => 2]], $this->header('Technic'))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $tv->id)
            ->assertJsonPath('data.draft.params.drawers', 2)
            ->assertJsonPath('data.draft.params.width', 160)
            ->assertJsonPath('data.draft.guessed', false);

        $this->assertSame(2, $tv->parts()->where('name', 'جلو کشو')->value('count'));
        $this->assertEquals(2, $tv->bomLines()->where('stock_item_id', $this->items['slide']->id)->value('quantity'));
        $this->assertNotNull($response->json('data.parts_count'));
    }

    public function test_regenerate_a_hand_edited_draft_needs_confirm()
    {
        [, , $tv] = $this->seeded();
        $tv->parts()->first()->update(['count' => 9]);
        $technic = $this->header('Technic');

        $this->postJson("/api/v1/admin/designs/{$tv->id}/regenerate", ['params' => []], $technic)
            ->assertStatus(409)->assertJsonPath('edited', true);
        $this->assertSame(9, $tv->parts()->first()->count);

        $this->postJson("/api/v1/admin/designs/{$tv->id}/regenerate", ['params' => [], 'confirm' => true], $technic)->assertStatus(200);
        $this->assertSame(2, $tv->parts()->first()->count);
    }

    public function test_regenerate_validation_and_permissions()
    {
        [, , $tv] = $this->seeded();
        $handWritten = $this->design($this->damtv220());
        $technic = $this->header('Technic');

        $this->postJson("/api/v1/admin/designs/{$handWritten->id}/regenerate", ['template' => 'cabinet', 'params' => []], $technic)->assertStatus(422);
        $this->postJson("/api/v1/admin/designs/{$tv->id}/regenerate", ['params' => ['drawers' => 99]], $technic)->assertStatus(422)
            ->assertJsonValidationErrors('params.drawers');
        $this->postJson("/api/v1/admin/designs/{$tv->id}/regenerate", ['template' => 'nope', 'params' => []], $technic)->assertStatus(422);
        // Impossible sizes are refused with a message, not a server error.
        $this->postJson("/api/v1/admin/designs/{$tv->id}/regenerate", ['params' => ['height' => 10, 'drawers' => 8, 'doors' => 0]], $technic)
            ->assertStatus(422)->assertJsonValidationErrors('params');

        foreach (['Sales', 'Admin'] as $role) {
            $this->postJson("/api/v1/admin/designs/{$tv->id}/regenerate", ['params' => []], $this->header($role))->assertStatus(403);
        }
    }

    // --- settings --------------------------------------------------------------------

    public function test_settings_read_and_update()
    {
        $technic = $this->header('Technic');

        $response = $this->getJson('/api/v1/admin/design-drafts/settings', $technic)->assertStatus(200)
            ->assertJsonPath('data.body_panel_material.id', $this->body16()->id)
            ->assertJsonPath('data.hardware.hinge.id', $this->items['hinge']->id)
            ->assertJsonPath('data.missing', []);
        $this->assertSame('cabinet', $response->json('data.categories.' . $this->cabinets->id));
        $this->productIn($this->cabinets, null);
        $options = collect($this->getJson('/api/v1/admin/design-drafts/settings', $technic)->json('data.category_options'))->keyBy('id');
        $this->assertSame(1, $options[$this->cabinets->id]['products_count']);
        $this->assertSame('کمد لباس', $options[$this->cabinets->id]['name']);

        $this->putJson('/api/v1/admin/design-drafts/settings', [
            'hardware' => ['handle' => null],
            'categories' => [$this->cabinets->id => 'wall_shelf'],
        ], $technic)->assertStatus(200)
            ->assertJsonPath('data.hardware.handle', null)
            ->assertJsonPath('data.hardware.hinge.id', $this->items['hinge']->id)
            ->assertJsonPath('data.categories.' . $this->cabinets->id, 'wall_shelf');

        $this->putJson('/api/v1/admin/design-drafts/settings', ['categories' => [$this->cabinets->id => 'nope']], $technic)
            ->assertStatus(422);
        $this->putJson('/api/v1/admin/design-drafts/settings', ['categories' => [999999 => 'cabinet']], $technic)
            ->assertStatus(422);
        $this->putJson('/api/v1/admin/design-drafts/settings', ['hardware' => ['hinge' => $this->items['body_sheet']->id]], $technic)
            ->assertStatus(422)->assertJsonValidationErrors('hardware.hinge');

        foreach (['Sales', 'Admin'] as $role) {
            $this->getJson('/api/v1/admin/design-drafts/settings', $this->header($role))->assertStatus(403);
            $this->putJson('/api/v1/admin/design-drafts/settings', [], $this->header($role))->assertStatus(403);
        }
    }

    public function test_no_cost_in_review_or_settings_for_a_super_admin()
    {
        $this->seeded();
        $admin = $this->header('SuperAdmin');

        $this->assertNoCost($this->getJson('/api/v1/admin/designs/review', $admin)->json());
        $this->assertNoCost($this->getJson('/api/v1/admin/design-drafts/settings', $admin)->json());
        $this->assertNoCost($this->getJson('/api/v1/admin/design-templates', $admin)->json());
    }

    // --- sheets and work orders --------------------------------------------------------

    public function test_draft_flag_on_design_sheet_and_work_order()
    {
        [$guessed] = $this->seeded();
        $technic = $this->header('Technic');

        $this->getJson("/api/v1/admin/designs/{$guessed->id}/sheet", $technic)->assertJsonPath('design.draft', true);
        $this->getJson("/api/v1/admin/products/{$guessed->product_id}/designs", $this->header('Sales'))
            ->assertJsonPath('data.0.draft.pending', true);

        $variation = Variation::factory()->create(['product_id' => $guessed->product_id]);
        $workOrder = $this->workOrderFor($variation);
        $this->assertTrue($workOrder->design_draft);
        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/cutting-list", $technic)->assertJsonPath('design.draft', true);
        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}", $technic)->assertJsonPath('data.design_draft', true);

        // Once approved, the sheet drops the notice and new work orders are not drafts.
        app(DesignService::class)->setApproved($guessed, true, null);
        $this->getJson("/api/v1/admin/designs/{$guessed->id}/sheet", $technic)->assertJsonPath('design.draft', false);
        $this->assertFalse($this->workOrderFor($variation)->design_draft);
    }
}
