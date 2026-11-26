<?php

namespace Tests\Feature\Production;

use App\Models\ProductDesign;
use App\Services\Production\DesignService;
use App\Services\Production\Drafts\DraftDesignService;
use App\Services\Production\Drafts\DraftEditedException;
use App\Services\Production\Drafts\TemplateRegistry;
use Illuminate\Validation\ValidationException;

class DraftDesignServiceTest extends DraftTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureDrafts();
    }

    private function drafts(): DraftDesignService
    {
        return app(DraftDesignService::class);
    }

    private function cabinetDraft(): ProductDesign
    {
        $product = $this->productIn($this->cabinets, '80*180*40');
        $template = app(TemplateRegistry::class)->get('cabinet');

        return $this->drafts()->generate($product, $template, ['width' => 80, 'height' => 180, 'depth' => 40, 'shelves' => 3, 'doors' => 2, 'drawers' => 0, 'back' => 1],
            ['name' => 'استاندارد', 'guessed' => true, 'source_dimensions' => '80*180*40']);
    }

    private function bomOf(ProductDesign $design): array
    {
        return $design->bomLines()->get()->mapWithKeys(fn ($l) => [$l->stock_item_id => (float) $l->quantity])->all();
    }

    public function test_a_first_draft_is_the_product_default_with_parts_and_bom()
    {
        $design = $this->cabinetDraft();

        $this->assertTrue($design->is_default);
        $this->assertTrue($design->isDraft());
        $this->assertNull($design->approved_at);
        $this->assertSame('cabinet', $design->draft_template);
        $this->assertTrue($design->draft_params['guessed']);
        $this->assertSame('80*40*180', $design->dimensions);
        $this->assertSame(5, $design->parts()->count());

        $bom = $this->bomOf($design);
        $this->assertGreaterThan(0, $bom[$this->items['body_sheet']->id]);
        $this->assertGreaterThan(0, $bom[$this->items['back_sheet']->id]);
        $this->assertGreaterThan(0, $bom[$this->items['edge']->id]);
        $this->assertSame(6.0, $bom[$this->items['hinge']->id]);
        $this->assertSame(2.0, $bom[$this->items['handle']->id]);
        $this->assertArrayNotHasKey($this->items['slide']->id, $bom);
        $this->assertSame(2, $design->parts()->distinct()->count('stock_item_id'));
        $this->assertFalse($this->drafts()->editedByHand($design));
    }

    public function test_regenerating_an_untouched_draft_replaces_its_content_and_keeps_the_design()
    {
        $design = $this->cabinetDraft();
        $rails = $this->rails();

        $again = $this->drafts()->regenerate($design, null, ['width' => 80, 'height' => 180, 'depth' => 40, 'shelves' => 3, 'doors' => 2, 'drawers' => 2, 'back' => 1], false);

        $this->assertSame($design->id, $again->id);
        $this->assertSame('استاندارد', $again->name);
        $this->assertTrue($again->is_default);
        $this->assertSame(2, $again->parts()->where('name', 'جلو کشو')->value('count'));
        // Drawers take the longest rail that fits 40 − 5 cm, not the old slide item.
        $this->assertSame(2.0, $this->bomOf($again)[$rails[35]->stock_item_id]);
        $this->assertArrayNotHasKey($this->items['slide']->id, $this->bomOf($again));
        $this->assertFalse($again->draft_params['guessed']);
        $this->assertSame('80*180*40', $again->draft_params['source_dimensions']);
    }

    public function test_a_hand_edited_draft_needs_confirmation()
    {
        $design = $this->cabinetDraft();
        $part = $design->parts()->first();
        $part->update(['length_mm' => 1700]);
        $this->assertTrue($this->drafts()->editedByHand($design));

        try {
            $this->drafts()->regenerate($design, null, $design->draft_params, false);
            $this->fail('Expected DraftEditedException');
        } catch (DraftEditedException) {
            $this->assertSame(1700, $design->parts()->first()->length_mm);
        }

        $this->drafts()->regenerate($design, null, $design->draft_params, true);
        $this->assertSame(1800, $design->parts()->first()->length_mm);
        $this->assertFalse($this->drafts()->editedByHand($design->fresh()));
    }

    public function test_a_hand_written_design_cannot_be_regenerated()
    {
        $design = $this->design($this->damtv220());

        $this->expectException(ValidationException::class);
        $this->drafts()->regenerate($design, 'cabinet', [], true);
    }

    public function test_regenerating_an_approved_draft_with_new_content_removes_approval()
    {
        $design = app(DesignService::class)->setApproved($this->cabinetDraft(), true, null);
        $this->assertFalse($design->isDraft());

        // The same parameters give the same content: approval stays.
        $same = $this->drafts()->regenerate($design, null, $design->draft_params, false);
        $this->assertNotNull($same->approved_at);

        $changed = $this->drafts()->regenerate($same, null, ['shelves' => 5] + $same->draft_params, false);
        $this->assertNull($changed->approved_at);
        $this->assertTrue($changed->isDraft());
    }

    public function test_another_template_can_be_chosen()
    {
        $design = $this->cabinetDraft();

        $shelf = $this->drafts()->regenerate($design, 'wall_shelf', ['width' => 80, 'height' => 180, 'depth' => 30, 'shelves' => 4], false);

        $this->assertSame('wall_shelf', $shelf->draft_template);
        $this->assertSame(0, $shelf->parts()->where('name', 'درب')->count());
    }
}
