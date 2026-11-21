<?php

namespace Tests\Feature\CostAdders;

use App\Console\Commands\SeedCostAdders;
use App\Models\CostAdder;
use App\Models\StockItem;

class SeedCostAddersTest extends AdderTestCase
{
    public function test_seeds_placeholders_once_and_never_overwrites()
    {
        $this->artisan('cost-adders:seed')->assertSuccessful();

        $this->assertSame(count(SeedCostAdders::ADDERS), CostAdder::count());
        $this->assertSame(3, StockItem::where('code', 'like', 'DFT-%')->count());
        $rail = CostAdder::where('name', 'ریل ۵۰')->firstOrFail();
        $this->assertSame([50, 'DFT-RAIL50', 0.0], [$rail->length_cm, $rail->stockItem->code, $rail->stockItem->quantity]);
        $vacuum = CostAdder::where('name', 'پرس وکیوم')->firstOrFail();
        $this->assertSame(['square_metre', 350000, 'اصلاح کنید'], [$vacuum->unit->value, $vacuum->unit_cost, $vacuum->note]);

        // A corrected price survives a second run, and nothing is duplicated.
        $vacuum->update(['unit_cost' => 420000, 'note' => null]);
        $this->artisan('cost-adders:seed')->assertSuccessful();
        $this->assertSame(count(SeedCostAdders::ADDERS), CostAdder::count());
        $this->assertSame(3, StockItem::where('code', 'like', 'DFT-%')->count());
        $this->assertSame(420000, $vacuum->fresh()->unit_cost);
    }

    public function test_dry_run_saves_nothing()
    {
        $this->artisan('cost-adders:seed --dry-run')->assertSuccessful();

        $this->assertSame(0, CostAdder::count());
        $this->assertSame(0, StockItem::count());
    }

    public function test_reuses_existing_stock_and_respects_an_existing_rail()
    {
        $item = StockItem::factory()->create(['code' => 'DFT-RAIL50', 'name' => 'ریل واقعی ۵۰']);
        $this->stockAdder('ریل پنجاه', $item, 'rail', 50);

        $this->artisan('cost-adders:seed')->assertSuccessful();

        $this->assertSame('ریل واقعی ۵۰', $item->fresh()->name);
        $this->assertFalse(CostAdder::where('name', 'ریل ۵۰')->exists());
        $this->assertSame(1, CostAdder::where('group', 'rail')->where('stock_item_id', $item->id)->count());
    }
}
