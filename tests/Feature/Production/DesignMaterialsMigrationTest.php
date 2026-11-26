<?php

namespace Tests\Feature\Production;

use App\Models\BomLine;
use App\Models\CuttingLayout;
use App\Models\ProductDesign;
use App\Models\StockItem;
use App\Services\Production\CuttingLayoutService;
use App\Services\Production\DesignMaterialsMigration;
use App\Services\Production\DesignService;
use App\Services\Production\Drafts\DraftSettings;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Migration A's backfill: parts move from panel materials to sheet items, with a report of every guess. */
class DesignMaterialsMigrationTest extends CuttingTestCase
{
    private int $body;
    private int $back;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->body = (int) DB::table('panel_materials')->where('name', 'بدنه ۱۶ میل')->value('id');
        $this->back = (int) DB::table('panel_materials')->where('name', 'پشت‌بند ۳ میل')->value('id');
    }

    /** A part as it was before: a panel material and no sheet item. */
    private function oldPart(ProductDesign $design, int $panel, array $attributes = []): void
    {
        DB::table('product_parts')->insert($attributes + [
            'design_id' => $design->id, 'position' => 0, 'name' => 'بغل', 'length_mm' => 1000, 'width_mm' => 400, 'count' => 2,
            'panel_material_id' => $panel, 'stock_item_id' => null, 'edge_length' => 0, 'edge_width' => 0, 'grain_locked' => false,
            'ops' => '[]', 'note' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A design with a saved mapping, as the old code stored it (the model no longer casts the column). */
    private function designMapped(array $map): ProductDesign
    {
        $design = ProductDesign::factory()->create();
        DB::table('product_designs')->where('id', $design->id)->update(['panel_map' => json_encode($map)]);

        return $design;
    }

    private function sheet(string $code, float $thickness, array $attributes = []): StockItem
    {
        return StockItem::factory()->create($attributes + ['code' => $code, 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => $thickness, 'length_mm' => null, 'width_mm' => null]);
    }

    private function run_(): array
    {
        return app(DesignMaterialsMigration::class)->run();
    }

    public function test_parts_take_the_mapped_sheet_then_the_most_mapped_then_one_of_the_same_thickness()
    {
        $col = $this->sheet('MDF16-COL', 16);
        $raw = $this->sheet('MDF16-RAW1', 16);
        $hdf = $this->sheet('HDF3', 3);
        $band = StockItem::factory()->create(['code' => 'EB22', 'kind' => 'edge_band', 'unit' => 'meter']);
        $map = fn ($sheet) => [['panel_material_id' => $this->body, 'sheet_stock_item_id' => $sheet->id, 'edge_band_stock_item_id' => $band->id]];

        $mapped = $this->designMapped($map($raw));
        $this->designMapped($map($col));
        $this->designMapped($map($col));
        $unmapped = ProductDesign::factory()->create();
        $this->oldPart($mapped, $this->body);
        $this->oldPart($unmapped, $this->body, ['name' => 'سقف']);
        $this->oldPart($unmapped, $this->back, ['name' => 'پشت‌بند', 'position' => 1]);

        $report = $this->run_();

        $this->assertSame($raw->id, $mapped->parts()->value('stock_item_id'));
        $this->assertSame([$col->id, $hdf->id], $unmapped->parts()->orderBy('position')->pluck('stock_item_id')->all());
        $this->assertEqualsCanonicalizing(
            [['سقف', 'MDF16-COL', 'most_mapped'], ['پشت‌بند', 'HDF3', 'same_thickness']],
            array_map(fn ($r) => [$r['part'], $r['stock_item'], $r['how']], $report),
        );
        Storage::disk('local')->assertExists(DesignMaterialsMigration::REPORT);
        $this->assertStringContainsString('same_thickness', Storage::disk('local')->get(DesignMaterialsMigration::REPORT));

        // Sheets take the panel material's waste and size, and their most frequent band.
        $col->refresh();
        $this->assertSame([20, 3660, 1830, $band->id], [$col->waste_percent, (int) $col->length_mm, (int) $col->width_mm, $col->default_edge_band_id]);

        // Running again changes nothing and guesses nothing.
        $this->assertSame([], $this->run_());
    }

    public function test_existing_sheet_lines_are_marked_calculated_without_changing_quantities()
    {
        $col = $this->sheet('MDF16-COL', 16);
        $hinge = StockItem::factory()->create();
        $design = $this->designMapped([['panel_material_id' => $this->body, 'sheet_stock_item_id' => $col->id]]);
        $this->oldPart($design, $this->body);
        BomLine::create(['design_id' => $design->id, 'stock_item_id' => $col->id, 'quantity' => 0.37]);
        BomLine::create(['design_id' => $design->id, 'stock_item_id' => $hinge->id, 'quantity' => 4]);

        $this->run_();

        $lines = $design->bomLines()->get()->keyBy('stock_item_id');
        $this->assertSame([0.37, 0.37], [$lines[$col->id]->quantity, $lines[$col->id]->calculated_quantity]);
        $this->assertSame([4.0, null], [$lines[$hinge->id]->quantity, $lines[$hinge->id]->calculated_quantity]);
    }

    public function test_a_layout_that_matched_its_parts_stays_up_to_date()
    {
        $col = $this->sheet('MDF16-COL', 16, ['length_mm' => 3660, 'width_mm' => 1830]);
        $design = $this->designMapped([['panel_material_id' => $this->body, 'sheet_stock_item_id' => $col->id]]);
        $this->oldPart($design, $this->body);
        // The fingerprint as the old code made it: parts by panel material and the panel material's sheet size.
        $old = sha1(json_encode([[['بغل', 1000, 400, 2, $this->body, false]], 1, [[$this->body, 3660, 1830]]]));
        $layout = CuttingLayout::create(['design_id' => $design->id, 'name' => '۱ عدد', 'units' => 1, 'kind' => CuttingLayout::GENERATED, 'fingerprint' => $old]);

        $this->run_();

        $this->assertFalse(app(CuttingLayoutService::class)->isOutdated($layout->fresh()));
    }

    public function test_untouched_drafts_stay_untouched_and_draft_settings_name_sheets()
    {
        $col = $this->sheet('MDF16-COL', 16);
        $hdf = $this->sheet('HDF3', 3);
        $mapping = [
            ['panel_material_id' => $this->body, 'sheet_stock_item_id' => $col->id, 'edge_band_stock_item_id' => null],
            ['panel_material_id' => $this->back, 'sheet_stock_item_id' => $hdf->id, 'edge_band_stock_item_id' => null],
        ];
        app(Settings::class)->set(DraftSettings::KEY, json_encode(['body_panel_material_id' => $this->body, 'back_panel_material_id' => $this->back, 'mapping' => $mapping, 'hardware' => []]));
        $design = $this->designMapped($mapping);
        $this->oldPart($design, $this->body);
        $old = sha1(json_encode([[['بغل', 1000, 400, 2, $this->body, 0, 0, false, [], null]], []]));
        DB::table('product_designs')->where('id', $design->id)->update(['draft_hash' => $old]);

        $this->run_();

        $this->assertSame(app(DesignService::class)->contentHash($design->fresh()), $design->fresh()->draft_hash);
        $settings = app(DraftSettings::class)->get();
        $this->assertSame([$col->id, $hdf->id], [$settings['body_sheet_item_id'], $settings['back_sheet_item_id']]);
        $this->assertSame([], app(DraftSettings::class)->missing());
    }
}
