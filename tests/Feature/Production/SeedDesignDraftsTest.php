<?php

namespace Tests\Feature\Production;

use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\ProductPart;
use App\Models\Variation;
use App\Services\Production\Drafts\DraftSeeder;

class SeedDesignDraftsTest extends DraftTestCase
{
    private function seedDrafts(array $options = []): array
    {
        return collect(app(DraftSeeder::class)->run($options))->keyBy('product_id')->all();
    }

    public function test_a_product_gets_a_default_draft_and_a_second_run_creates_nothing()
    {
        $this->configureDrafts();
        $product = $this->productIn($this->cabinets, '180*80*30');

        $rows = $this->seedDrafts();
        $this->assertSame(DraftSeeder::CREATED, $rows[$product->id]['outcome']);
        $this->assertSame('بله', $rows[$product->id]['guessed']);

        $design = ProductDesign::where('product_id', $product->id)->sole();
        $this->assertSame('استاندارد', $design->name);
        $this->assertTrue($design->is_default);
        $this->assertTrue($design->active);
        $this->assertTrue($design->isDraft());
        $this->assertGreaterThan(0, $design->parts()->count());
        $this->assertGreaterThan(0, $design->bomLines()->count());

        $again = $this->seedDrafts();
        $this->assertSame(DraftSeeder::HAS_DESIGN, $again[$product->id]['reason']);
        $this->assertSame(1, ProductDesign::where('product_id', $product->id)->count());
    }

    public function test_a_product_with_a_design_is_untouched()
    {
        $this->configureDrafts();
        $product = $this->damtv220();
        $product->categories()->attach($this->tvUnits->id);
        $parts = ProductPart::count();

        $rows = $this->seedDrafts();

        $this->assertSame(DraftSeeder::SKIPPED, $rows[$product->id]['outcome']);
        $this->assertSame(DraftSeeder::HAS_DESIGN, $rows[$product->id]['reason']);
        $this->assertSame($parts, ProductPart::count());
        $this->assertNull(ProductDesign::where('product_id', $product->id)->sole()->draft_template);
    }

    public function test_dry_run_saves_nothing()
    {
        $this->configureDrafts();
        $product = $this->productIn($this->cabinets, '180*80*30');

        $rows = $this->seedDrafts(['dry_run' => true]);

        $this->assertSame(DraftSeeder::WOULD_CREATE, $rows[$product->id]['outcome']);
        $this->assertSame(0, ProductDesign::count());
    }

    public function test_two_sizes_in_two_colours()
    {
        $this->configureDrafts();
        $product = $this->productIn($this->tvUnits, '160*50*40');
        Variation::factory()->create(['product_id' => $product->id, 'size' => '160*50*40']);
        Variation::factory()->create(['product_id' => $product->id, 'size' => '160*50*40']);
        $v180white = Variation::factory()->create(['product_id' => $product->id, 'size' => '180*50*40']);
        $v180black = Variation::factory()->create(['product_id' => $product->id, 'size' => '180*50*40']);

        $rows = $this->seedDrafts();

        $designs = ProductDesign::where('product_id', $product->id)->get()->keyBy('name');
        $this->assertSame(['160*50*40', '180*50*40'], $designs->keys()->sort()->values()->all());
        $this->assertTrue($designs['160*50*40']->is_default);
        $this->assertNull($designs['160*50*40']->variation_id);
        $this->assertFalse($designs['180*50*40']->is_default);
        $this->assertSame($v180white->id, $designs['180*50*40']->variation_id);
        // Each size draft is built from its own width.
        $this->assertSame(1796, $designs['180*50*40']->parts()->where('name', 'پشت‌بند')->value('length_mm'));
        $this->assertSame(1596, $designs['160*50*40']->parts()->where('name', 'پشت‌بند')->value('length_mm'));
        $this->assertStringContainsString(DraftSeeder::SAME_SIZE . ': #' . $v180black->id, $rows[$product->id]['reason']);
    }

    public function test_skipped_categories_unmapped_categories_and_unreadable_dimensions_are_reported()
    {
        $this->configureDrafts();
        $chair = $this->productIn($this->chairs, 'استاندارد');
        $set = $this->productIn($this->tvUnits, 'میزتلویزیون 160*40*42 / کنسول 150*40*90');
        $loose = Product::factory()->create(['dimensions' => '80*180*40']);

        $rows = $this->seedDrafts();

        $this->assertSame(DraftSeeder::SKIP_CATEGORY, $rows[$chair->id]['reason']);
        $this->assertSame(DraftSeeder::UNREADABLE, $rows[$set->id]['reason']);
        $this->assertSame(DraftSeeder::UNMAPPED, $rows[$loose->id]['reason']);
        $this->assertSame(0, ProductDesign::count());
    }

    public function test_one_off_products_are_left_out()
    {
        $this->configureDrafts();
        $oneOff = $this->productIn($this->cabinets, '80*180*40');
        $oneOff->forceFill(['one_off' => true])->save();

        $this->assertArrayNotHasKey($oneOff->id, $this->seedDrafts());
    }

    public function test_filters_and_limit()
    {
        $this->configureDrafts();
        $a = $this->productIn($this->cabinets, '80*180*40');
        $b = $this->productIn($this->tvUnits, '160*50*40');
        $c = $this->productIn($this->tvUnits, '180*50*40');

        $this->assertSame([$a->id], array_keys($this->seedDrafts(['dry_run' => true, 'categories' => [$this->cabinets->id]])));
        $this->assertSame([$b->id], array_keys($this->seedDrafts(['dry_run' => true, 'products' => [$b->id]])));

        $this->seedDrafts(['limit' => 1]);
        $this->assertSame(1, ProductDesign::count());
        $this->assertNotNull($c);
    }

    public function test_the_command_stops_without_settings_and_writes_a_report_when_configured()
    {
        $this->artisan('designs:seed-drafts')
            ->expectsOutputToContain('ورق بدنه')
            ->assertFailed();

        $this->configureDrafts();
        $this->productIn($this->cabinets, '80*180*40');

        $this->artisan('designs:seed-drafts', ['--dry-run' => true])
            ->expectsOutputToContain('Report:')
            ->assertSuccessful();
        $this->assertSame(0, ProductDesign::count());

        $report = collect(glob(storage_path('app/reports/design-drafts-*.csv')))->sort()->last();
        $content = file_get_contents($report);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString(DraftSeeder::WOULD_CREATE, $content);
        @unlink($report);
    }
}
