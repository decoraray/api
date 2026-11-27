<?php

namespace Tests\Feature\Variations;

use App\Services\Production\MaterialBuild;

class MaterialBuildTest extends VariationTestCase
{
    /** [part name => sheet code] */
    private function sheetsOf($parts): array
    {
        $codes = \App\Models\StockItem::pluck('code', 'id');

        return $parts->mapWithKeys(fn ($p) => [$p->name => $codes[$p->stock_item_id]])->all();
    }

    public function test_the_body_board_moves_to_the_material_sheet_and_the_back_stays()
    {
        $design = $this->unit();
        $material = $this->material('هایگلاس', $this->gloss());

        $built = app(MaterialBuild::class)->parts($design->parts, $material);

        $sheets = $this->sheetsOf($built);
        $this->assertSame('HDF-3-WH', $sheets['پشت بند']);
        unset($sheets['پشت بند']);
        $this->assertSame(['HIGLOSS16'], array_values(array_unique($sheets)));
        // The design's own parts are untouched.
        $this->assertSame(['MDF-16-WAL', 'HDF-3-WH'], array_values(array_unique($this->sheetsOf($design->parts()->get()))));
        $this->assertSame(0, $built->whereNotNull('id')->count());
    }

    public function test_fronts_go_to_the_front_sheet()
    {
        $design = $this->unit();
        $front = $this->stock(0, ['code' => 'MDF16-RAW1', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16]);
        $material = $this->material('بدنه ملامینه درها ام دی اف', $this->gloss(), $front);

        $sheets = $this->sheetsOf(app(MaterialBuild::class)->parts($design->parts, $material));

        $this->assertSame('MDF16-RAW1', $sheets['درب ها']);
        $this->assertSame('HIGLOSS16', $sheets['کف']);
        $this->assertSame('HDF-3-WH', $sheets['پشت بند']);
    }

    public function test_a_material_without_sheets_changes_nothing()
    {
        $design = $this->unit();

        foreach ([null, $this->material('چرم')] as $material) {
            $built = app(MaterialBuild::class)->parts($design->parts, $material);
            $this->assertSame($design->parts->pluck('stock_item_id')->all(), $built->pluck('stock_item_id')->all());
        }
    }
}
