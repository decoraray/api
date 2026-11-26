<?php

namespace Tests\Feature\Production;

use App\Models\Category;
use App\Models\CostAdder;
use App\Models\Product;
use App\Models\StockItem;
use App\Services\Production\Drafts\DraftSettings;

abstract class DraftTestCase extends CuttingTestCase
{
    /** @var array<string, StockItem> */
    protected array $items = [];

    protected Category $cabinets;
    protected Category $tvUnits;
    protected Category $chairs;

    /** Sheets, edge band and hardware in stock, the draft settings filled, and three mapped categories. */
    protected function configureDrafts(array $hardware = ['hinge', 'slide', 'handle', 'leg']): void
    {
        $this->items['edge'] = StockItem::factory()->create(['name' => 'نوار لبه سفید', 'kind' => 'edge_band', 'unit' => 'meter']);
        $this->items['body_sheet'] = StockItem::factory()->mdf()->create(['default_edge_band_id' => $this->items['edge']->id]);
        $this->items['back_sheet'] = StockItem::factory()->mdf()->create(['name' => 'فیبر ۳', 'thickness_mm' => 3, 'waste_percent' => 10]);
        foreach (['hinge' => 'لولا', 'slide' => 'ریل کشو', 'handle' => 'دستگیره', 'leg' => 'پایه'] as $role => $name) {
            $this->items[$role] = StockItem::factory()->create(['name' => $name]);
        }

        $this->cabinets = Category::factory()->create(['name' => 'کمد لباس']);
        $this->tvUnits = Category::factory()->create(['name' => 'میز تلویزیون']);
        $this->chairs = Category::factory()->create(['name' => 'صندلی']);

        app(DraftSettings::class)->save([
            'body_sheet_item_id' => $this->items['body_sheet']->id,
            'back_sheet_item_id' => $this->items['back_sheet']->id,
            'hardware' => collect($hardware)->mapWithKeys(fn ($role) => [$role => $this->items[$role]->id])->all(),
            'categories' => [$this->cabinets->id => 'cabinet', $this->tvUnits->id => 'low_unit', $this->chairs->id => 'skip'],
        ]);
    }

    /**
     * Rail adders with their stock items, one per length (cm).
     *
     * @return array<int, CostAdder> by length
     */
    protected function rails(array $lengths = [25, 30, 35, 40, 45, 50]): array
    {
        $rails = [];
        foreach ($lengths as $length) {
            $item = StockItem::factory()->create(['code' => "RAIL-{$length}", 'name' => "ریل کشو {$length}"]);
            $rails[$length] = CostAdder::create(['name' => "ریل {$length}", 'group' => 'rail', 'basis' => 'stock', 'stock_item_id' => $item->id, 'length_cm' => $length]);
        }

        return $rails;
    }

    protected function productIn(Category $category, ?string $dimensions, array $attributes = []): Product
    {
        $product = Product::factory()->create(['dimensions' => $dimensions] + $attributes);
        $product->categories()->attach($category->id);

        return $product;
    }
}
