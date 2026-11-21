<?php

namespace Tests\Feature\CostAdders;

use App\Console\Commands\ImportMaterialPrices;
use App\Models\BomLine;
use App\Models\CostAdder;
use App\Models\DesignAdder;
use App\Models\StockItem;
use App\Models\Variation;
use App\Models\WorkOrderAdder;
use Spatie\Activitylog\Models\Activity;

class ImportMaterialPricesTest extends AdderTestCase
{
    /** The placeholders production has: the draft seeding's items, the old handle and cost-adders:seed's output. */
    private function placeholders(): void
    {
        foreach ([
            ['DFT-MDF16', 'ورق ۱۶ میل (پیش‌فرض)', 'mdf_sheet', 'sheet', 16],
            ['DFT-HDF3', 'ورق ۳ میل (پیش‌فرض)', 'mdf_sheet', 'sheet', 3],
            ['DFT-HINGE', 'لولا (پیش‌فرض)', 'hardware', 'piece', null],
            ['DFT-SLIDE45', 'ریل ۴۵ (پیش‌فرض)', 'hardware', 'piece', null],
            ['DFT-LEG', 'پایه (پیش‌فرض)', 'hardware', 'piece', null],
            ['H-77', '۱۲۳', 'hardware', 'piece', null],
        ] as [$code, $name, $kind, $unit, $thickness]) {
            StockItem::create(['code' => $code, 'name' => $name, 'kind' => $kind, 'unit' => $unit, 'thickness_mm' => $thickness, 'note' => 'اصلاح کنید']);
        }
        $this->artisan('cost-adders:seed')->assertSuccessful();
    }

    public function test_renames_placeholders_in_place_and_draft_boms_follow()
    {
        $this->placeholders();
        $mdf = StockItem::where('code', 'DFT-MDF16')->firstOrFail();
        $design = $this->design(Variation::factory()->create()->product);
        $line = BomLine::create(['design_id' => $design->id, 'stock_item_id' => $mdf->id, 'quantity' => 0.5]);

        $this->artisan('materials:import-prices')->assertSuccessful();

        $mdf->refresh();
        $this->assertSame(['MDF16-COL', 'ام دی اف رنگی ۱۶ میل', 3660, 1830, null], [$mdf->code, $mdf->name, $mdf->length_mm, $mdf->width_mm, $mdf->note]);
        $this->assertSame($mdf->id, $line->fresh()->stock_item_id);
        // 2,900,000 per m² × 3.66 × 1.83
        $this->assertSame(19423620, $mdf->average_cost);

        $this->assertSame('HANDLE-1H', StockItem::where('name', 'دستگیره تک سوراخ')->value('code'));
        $this->assertFalse(StockItem::where('name', '۱۲۳')->exists());
        $this->assertSame(['RAIL-45', 315000], StockItem::where('name', 'ریل کشو ۴۵ سانتی (جفت)')->get(['code', 'average_cost'])->map(fn ($i) => [$i->code, $i->average_cost])->first());
        $this->assertFalse(StockItem::where('code', 'like', 'DFT-%')->where('code', '!=', 'DFT-EB22')->exists());
        $this->assertSame(count(ImportMaterialPrices::ITEMS), StockItem::count());
    }

    public function test_creates_missing_items_at_zero_stock()
    {
        $this->artisan('materials:import-prices')->assertSuccessful();

        $rail = StockItem::where('code', 'RAIL-35')->firstOrFail();
        $this->assertSame(['ریل کشو ۳۵ سانتی (جفت)', 'hardware', 'piece', 0.0, 240000], [$rail->name, $rail->kind->value, $rail->unit->value, $rail->quantity, $rail->average_cost]);
        $mirror = StockItem::where('code', 'MIRROR')->firstOrFail();
        $this->assertSame(['square_metre', 3000000], [$mirror->unit->value, $mirror->average_cost]);
        $this->assertSame(5358240, StockItem::where('code', 'HDF3')->value('average_cost')); // 800,000 × 3.66 × 1.83
    }

    public function test_an_item_with_stock_keeps_its_average_and_its_unit()
    {
        $hinge = $this->stock(40, ['code' => 'HINGE', 'kind' => 'hardware', 'unit' => 'piece'], 120000);
        $rod = $this->stock(3, ['code' => 'HANGER-ROD', 'name' => 'لوله', 'kind' => 'hardware', 'unit' => 'piece'], 50000);

        $this->artisan('materials:import-prices')
            ->expectsOutputToContain('رسید با بهای 140,000')
            ->expectsOutputToContain('رد شد: «HANGER-ROD» موجودی دارد')
            ->assertSuccessful();

        $this->assertSame([120000, 'لولا'], [$hinge->fresh()->average_cost, $hinge->fresh()->name]);
        $this->assertSame(['piece', 'لوله', 50000], [$rod->fresh()->unit->value, $rod->fresh()->name, $rod->fresh()->average_cost]);
    }

    public function test_adders_are_renamed_and_rails_added()
    {
        $this->placeholders();
        $poly = CostAdder::where('name', 'رنگ پلی‌یورتان')->firstOrFail();

        $this->artisan('materials:import-prices')->assertSuccessful();

        $poly->refresh();
        $this->assertSame(['دستمزد رنگ کوچک', 'colour', 'piece', 2500000, null], [$poly->name, $poly->group->value, $poly->unit->value, $poly->unit_cost, $poly->note]);
        $this->assertSame(900000, CostAdder::where('name', 'روکش وکیوم')->value('unit_cost'));
        $this->assertFalse(CostAdder::where('name', 'پرس وکیوم')->exists());
        $this->assertSame(3000, CostAdder::where('name', 'یراق گونیا و پیچ')->value('unit_cost'));
        $this->assertTrue(CostAdder::where('name', 'پیچ و منگنه')->exists(), 'not on the list, left alone');

        $rails = CostAdder::where('group', 'rail')->with('stockItem')->orderBy('length_cm')->get();
        $this->assertSame([[25, 'RAIL-25'], [30, 'RAIL-30'], [35, 'RAIL-35'], [40, 'RAIL-40'], [45, 'RAIL-45'], [50, 'RAIL-50']],
            $rails->map(fn ($a) => [$a->length_cm, $a->stockItem->code])->all());
        $this->assertSame('MAGNET', CostAdder::where('name', 'مگنت درب')->firstOrFail()->stockItem->code);
        $this->assertSame('MIRROR', CostAdder::where('name', 'آینه')->firstOrFail()->stockItem->code);
    }

    public function test_an_adder_in_use_keeps_its_unit_and_work_orders_keep_their_cost()
    {
        $vacuum = $this->serviceAdder('پرس وکیوم', 'vacuum', 'square_metre', 350000);
        $colour = $this->serviceAdder('رنگ پلی‌یورتان', 'colour', 'square_metre', 450000);
        $design = $this->design(Variation::factory()->create()->product);
        DesignAdder::create(['design_id' => $design->id, 'cost_adder_id' => $colour->id, 'quantity' => 1.5]);
        $workOrder = $this->workOrderFor(null);
        $onOrder = WorkOrderAdder::create(['work_order_id' => $workOrder->id, 'cost_adder_id' => $vacuum->id, 'quantity' => 2, 'unit_cost' => 350000]);

        $this->artisan('materials:import-prices')
            ->expectsOutputToContain('رد شد: «رنگ پلی‌یورتان» روی طرح یا دستور تولید استفاده شده')
            ->assertSuccessful();

        // Same unit (m²), so the used vacuum adder is renamed and repriced; the work order keeps its cost.
        $this->assertSame(['روکش وکیوم', 900000], [$vacuum->fresh()->name, $vacuum->fresh()->unit_cost]);
        $this->assertSame(350000, $onOrder->fresh()->unit_cost);
        $this->assertSame(['رنگ پلی‌یورتان', 'square_metre', 450000], [$colour->fresh()->name, $colour->fresh()->unit->value, $colour->fresh()->unit_cost]);
    }

    public function test_dry_run_saves_nothing()
    {
        $this->placeholders();
        $before = [StockItem::orderBy('id')->get()->toArray(), CostAdder::orderBy('id')->get()->toArray(), Activity::count()];

        $this->artisan('materials:import-prices --dry-run')
            ->expectsOutputToContain('از «DFT-HINGE» تغییر نام داد')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame($before, [StockItem::orderBy('id')->get()->toArray(), CostAdder::orderBy('id')->get()->toArray(), Activity::count()]);
    }

    public function test_a_second_run_changes_nothing_and_logs_nothing()
    {
        $this->placeholders();
        $this->artisan('materials:import-prices')->assertSuccessful();
        $logged = Activity::count();
        $items = StockItem::orderBy('id')->get()->toArray();

        $this->artisan('materials:import-prices')->assertSuccessful();

        $this->assertSame($logged, Activity::count());
        $this->assertSame($items, StockItem::orderBy('id')->get()->toArray());
        $this->assertSame(count(ImportMaterialPrices::ITEMS), StockItem::count());
        $this->assertSame(count(ImportMaterialPrices::ADDERS) + 1, CostAdder::count()); // + «پیچ و منگنه»
    }

    public function test_changes_are_audited_and_tagged_with_the_command()
    {
        $this->placeholders();
        $hinge = StockItem::where('code', 'DFT-HINGE')->firstOrFail();

        $this->artisan('materials:import-prices')->assertSuccessful();

        $cost = Activity::where('subject_type', StockItem::class)->where('subject_id', $hinge->id)->where('description', 'average-cost-set')->firstOrFail();
        $this->assertSame([0, 140000], [$cost->properties['old']['average_cost'], $cost->properties['attributes']['average_cost']]);
        $this->assertSame(ImportMaterialPrices::SOURCE, $cost->properties['source']);

        $rename = Activity::where('subject_type', StockItem::class)->where('subject_id', $hinge->id)->where('event', 'updated')->where('description', '!=', 'average-cost-set')->firstOrFail();
        $this->assertSame(['DFT-HINGE', 'HINGE'], [$rename->properties['old']['code'], $rename->properties['attributes']['code']]);
        $this->assertSame(ImportMaterialPrices::SOURCE, $rename->properties['source']);
        $this->assertTrue(Activity::where('subject_type', CostAdder::class)->where('event', 'updated')->exists());
    }
}
