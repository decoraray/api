<?php

namespace Tests\Feature\Production;

use App\Models\Category;
use App\Models\StockItem;
use App\Services\Production\Drafts\DraftException;
use App\Services\Production\Drafts\DraftSettings;
use Illuminate\Support\Facades\Validator;

class DraftSettingsTest extends DraftTestCase
{
    private function settings(): DraftSettings
    {
        return app(DraftSettings::class);
    }

    public function test_missing_body_material_stops_drafts()
    {
        $this->assertSame(['ورق بدنه'], $this->settings()->missing());

        $this->expectException(DraftException::class);
        $this->expectExceptionMessage('ورق بدنه');
        $this->settings()->materials();
    }

    public function test_a_body_material_without_a_sheet_item_is_reported()
    {
        $this->settings()->save(['body_panel_material_id' => $this->body16()->id]);

        $this->assertSame(['قلم انبار ورق برای «بدنه ۱۶ میل»'], $this->settings()->missing());
    }

    public function test_configured_settings_are_ready_and_give_materials_and_hardware()
    {
        $this->configureDrafts(['hinge', 'slide']);

        $this->assertSame([], $this->settings()->missing());
        $this->assertSame($this->body16()->id, $this->settings()->materials()->body->id);
        // Roles left unset give no stock item, so no BOM line.
        $this->assertSame(['hinge', 'slide'], array_keys($this->settings()->hardwareItems()));
    }

    public function test_wrong_stock_item_kinds_are_rejected()
    {
        $sheet = StockItem::factory()->mdf()->create();
        $hinge = StockItem::factory()->create();

        $validator = Validator::make([
            'hardware' => ['hinge' => $sheet->id],
            'mapping' => [['panel_material_id' => $this->body16()->id, 'sheet_stock_item_id' => $hinge->id]],
        ], $this->settings()->rules());

        $this->assertTrue($validator->errors()->has('hardware.hinge'));
        $this->assertTrue($validator->errors()->has('mapping.0.sheet_stock_item_id'));
    }

    public function test_template_for_a_product_follows_its_categories()
    {
        $this->configureDrafts();
        $other = Category::factory()->create();

        $this->assertSame('cabinet', $this->settings()->templateFor($this->productIn($this->cabinets, '80*180*40')));
        $this->assertNull($this->settings()->templateFor($this->productIn($other, '80*180*40')));

        // Any skipped category wins: a chair also listed under TV units is not built here.
        $both = $this->productIn($this->tvUnits, null);
        $both->categories()->attach($this->chairs->id);
        $this->assertSame('skip', $this->settings()->templateFor($both->fresh()));
    }
}
