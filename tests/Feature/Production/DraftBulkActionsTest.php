<?php

namespace Tests\Feature\Production;

use App\Models\CostAdder;
use App\Models\ProductDesign;
use App\Models\StockItem;
use App\Services\Production\Drafts\DraftDesignService;
use App\Services\Production\Drafts\DraftSettings;
use App\Services\Production\Drafts\MaterialRules;
use App\Services\Production\Drafts\TemplateRegistry;
use Spatie\Activitylog\Models\Activity;

/** Selecting drafts in the review queue and acting on many at once. */
class DraftBulkActionsTest extends DraftTestCase
{
    private const LOW = ['width' => 120, 'height' => 50, 'depth' => 40, 'shelves' => 0, 'doors' => 2, 'drawers' => 0, 'back' => 1, 'legs' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDrafts();
    }

    /** @return ProductDesign[] */
    private function drafts(int $count, string $title = 'میز تلویزیون'): array
    {
        $template = app(TemplateRegistry::class)->get('low_unit');
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $product = $this->productIn($this->tvUnits, null, ['title' => "{$title} {$i}"]);
            $out[] = app(DraftDesignService::class)->generate($product, $template, self::LOW, ['name' => 'استاندارد', 'is_default' => true]);
        }

        return $out;
    }

    private function bulk(array $payload, string $role = 'SuperAdmin')
    {
        return $this->postJson('/api/v1/admin/designs/bulk', $payload, $this->header($role));
    }

    private function ids(array $designs): array
    {
        return array_map(fn ($d) => $d->id, $designs);
    }

    public function test_select_all_matching()
    {
        $designs = $this->drafts(30);

        $this->getJson('/api/v1/admin/designs/review?per_page=25', $this->header('Technic'))->assertJsonCount(25, 'data');
        $response = $this->getJson('/api/v1/admin/designs/review?ids_only=1&template=low_unit', $this->header('Technic'))->assertOk();

        $this->assertSame(30, $response->json('total'));
        $this->assertEqualsCanonicalizing($this->ids($designs), $response->json('ids'));
        $this->getJson('/api/v1/admin/designs/review?ids_only=1&template=cabinet', $this->header('Technic'))->assertJsonPath('total', 0);
    }

    public function test_approve_a_batch()
    {
        $designs = $this->drafts(4);
        $designs[0]->forceFill(['approved_at' => now()])->save();

        $response = $this->bulk(['action' => 'approve', 'ids' => $this->ids($designs)])->assertOk();

        $response->assertJsonPath('data.counts.done', 3)->assertJsonPath('data.counts.already_approved', 1);
        $this->assertSame($designs[0]->id, $response->json('data.skipped.0.id'));
        $this->assertSame(4, ProductDesign::whereNotNull('approved_at')->count());
        $this->getJson('/api/v1/admin/designs/review', $this->header('Technic'))->assertJsonCount(0, 'data');
    }

    public function test_approve_without_permission()
    {
        $designs = $this->drafts(2);

        $this->bulk(['action' => 'approve', 'ids' => $this->ids($designs)], 'Technic')->assertForbidden();

        $this->assertSame(0, ProductDesign::whereNotNull('approved_at')->count());
    }

    public function test_bulk_needs_bom_manage_or_approve()
    {
        $this->bulk(['action' => 'rebuild', 'ids' => [1]], 'Sales')->assertForbidden();
    }

    public function test_too_many()
    {
        $designs = $this->drafts(1);

        $this->bulk(['action' => 'approve', 'ids' => range(1, 1001)])->assertStatus(422)->assertJsonValidationErrors(['ids']);

        $this->assertNull($designs[0]->refresh()->approved_at);
    }

    public function test_unknown_and_hand_written_designs_are_reported()
    {
        [$draft] = $this->drafts(1);
        $handWritten = ProductDesign::factory()->create();

        $response = $this->bulk(['action' => 'approve', 'ids' => [$draft->id, $handWritten->id, 999999]])->assertOk();

        $response->assertJsonPath('data.counts.done', 1)->assertJsonPath('data.counts.not_draft', 1)->assertJsonPath('data.counts.not_found', 1);
        $this->assertNull($handWritten->refresh()->approved_at);
    }

    public function test_body_sheet_to_high_gloss()
    {
        $designs = $this->drafts(3);
        $gloss = StockItem::factory()->mdf()->create(['code' => 'HIGLOSS16', 'name' => 'هایگلاس ۱۶ میل']);
        $other = StockItem::factory()->mdf()->create(['name' => 'ورق دیگر']);
        // One draft has no MDF رنگی at all.
        $designs[2]->bomLines()->where('stock_item_id', $this->items['body_sheet']->id)->update(['stock_item_id' => $other->id]);
        $designs[2]->forceFill(['panel_map' => array_map(fn ($r) => ['sheet_stock_item_id' => $r['sheet_stock_item_id'] === $this->items['body_sheet']->id ? $other->id : $r['sheet_stock_item_id']] + $r, $designs[2]->panel_map)])->save();
        $quantity = (float) $designs[0]->bomLines()->where('stock_item_id', $this->items['body_sheet']->id)->value('quantity');

        $response = $this->bulk(['action' => 'swap', 'ids' => $this->ids($designs), 'from_stock_item_id' => $this->items['body_sheet']->id, 'to_stock_item_id' => $gloss->id], 'Technic')->assertOk();

        $response->assertJsonPath('data.counts.done', 2)->assertJsonPath('data.counts.no_match', 1);
        foreach ([$designs[0], $designs[1]] as $design) {
            $design->refresh();
            $this->assertEquals($quantity, $design->bomLines()->where('stock_item_id', $gloss->id)->value('quantity'));
            $this->assertFalse($design->bomLines()->where('stock_item_id', $this->items['body_sheet']->id)->exists());
            $this->assertSame($gloss->id, collect($design->panel_map)->firstWhere('panel_material_id', $this->body16()->id)['sheet_stock_item_id']);
            $this->assertTrue(app(DraftDesignService::class)->editedByHand($design));
        }
        $sheet = $this->getJson("/api/v1/admin/designs/{$designs[0]->id}/sheet", $this->header('Technic'))->assertOk();
        $this->assertContains('هایگلاس ۱۶ میل', array_column(array_column($sheet->json('bom_lines'), 'stock_item'), 'name'));
    }

    public function test_swap_merges_into_an_existing_line()
    {
        [$design] = $this->drafts(1);
        $hinges = (float) $design->bomLines()->where('stock_item_id', $this->items['hinge']->id)->value('quantity');
        $glassHinge = StockItem::factory()->create(['name' => 'لولا شیشه']);
        $design->bomLines()->create(['stock_item_id' => $glassHinge->id, 'quantity' => 2]);

        $this->bulk(['action' => 'swap', 'ids' => [$design->id], 'from_stock_item_id' => $this->items['hinge']->id, 'to_stock_item_id' => $glassHinge->id], 'Technic')->assertOk();

        $this->assertEquals($hinges + 2, $design->bomLines()->where('stock_item_id', $glassHinge->id)->value('quantity'));
        $this->assertSame(1, $design->bomLines()->where('stock_item_id', $glassHinge->id)->count());
    }

    public function test_swap_different_kinds()
    {
        $designs = $this->drafts(1);

        $this->bulk(['action' => 'swap', 'ids' => $this->ids($designs), 'from_stock_item_id' => $this->items['hinge']->id, 'to_stock_item_id' => $this->items['body_sheet']->id], 'Technic')
            ->assertStatus(422)->assertJsonValidationErrors(['to_stock_item_id']);

        $this->assertTrue($designs[0]->bomLines()->where('stock_item_id', $this->items['hinge']->id)->exists());
    }

    public function test_vacuum_on_a_batch_with_audit()
    {
        $designs = $this->drafts(20);
        $vacuum = CostAdder::create(['name' => 'روکش وکیوم', 'group' => 'vacuum', 'basis' => 'service', 'unit' => 'square_metre', 'unit_cost' => 900000]);

        $this->bulk(['action' => 'add_adder', 'ids' => $this->ids($designs), 'cost_adder_id' => $vacuum->id, 'quantity' => 1.5], 'Technic')
            ->assertOk()->assertJsonPath('data.counts.done', 20);

        foreach ($designs as $design) {
            $this->assertEquals(1.5, $design->adders()->where('cost_adder_id', $vacuum->id)->value('quantity'));
        }
        $this->assertSame(20, Activity::where('description', 'design-adder-added')->count());
        $batch = Activity::where('description', 'designs-bulk')->sole();
        $this->assertSame('add_adder', $batch->properties['action']);
        $this->assertSame(20, $batch->properties['count']);
        $this->assertSame($vacuum->id, $batch->properties['params']['cost_adder_id']);
        $this->assertNotNull($batch->causer_id);
    }

    public function test_add_an_inactive_adder()
    {
        $designs = $this->drafts(1);
        $vacuum = CostAdder::create(['name' => 'روکش وکیوم', 'group' => 'vacuum', 'basis' => 'service', 'unit' => 'square_metre', 'unit_cost' => 900000, 'active' => false]);

        $this->bulk(['action' => 'add_adder', 'ids' => $this->ids($designs), 'cost_adder_id' => $vacuum->id, 'quantity' => 1], 'Technic')->assertStatus(422);
    }

    public function test_rebuild_after_changing_a_rule()
    {
        // Drafts made before the high-gloss sheet existed, so no rule could choose it.
        $designs = $this->drafts(3, 'میز تلویزیون براق');
        $gloss = StockItem::factory()->mdf()->create(['code' => 'HIGLOSS16', 'name' => 'هایگلاس ۱۶ میل']);
        $designs[2]->parts()->first()->update(['count' => 5]);
        $rules = MaterialRules::defaults();
        $rules['sheet_rules'] = [['words' => ['براق'], 'templates' => [], 'sheet_stock_item_id' => $gloss->id]];
        app(DraftSettings::class)->save(['rules' => $rules]);

        $response = $this->bulk(['action' => 'rebuild', 'ids' => $this->ids($designs)], 'Technic')->assertOk();

        $response->assertJsonPath('data.counts.done', 2)->assertJsonPath('data.counts.edited', 1)->assertJsonPath('data.skipped.0.reason', 'دستی ویرایش شده');
        $this->assertTrue($designs[0]->bomLines()->where('stock_item_id', $gloss->id)->exists());
        $this->assertFalse($designs[2]->bomLines()->where('stock_item_id', $gloss->id)->exists());
    }
}
