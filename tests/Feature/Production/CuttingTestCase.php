<?php

namespace Tests\Feature\Production;

use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\ProductPart;
use App\Models\StockItem;
use App\Models\Variation;
use App\Services\Production\CuttingListService;

abstract class CuttingTestCase extends MaterialTestCase
{
    protected function cutting(): CuttingListService
    {
        return app(CuttingListService::class);
    }

    /** The 16 mm body sheet: walnut MDF, 20% waste, standard size, no default edge band. */
    protected function body16(): StockItem
    {
        return StockItem::firstOrCreate(['code' => 'MDF-16-WAL'], [
            'name' => 'ام دی اف گردویی ۱۶', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16, 'color' => 'گردویی',
            'length_mm' => 3660, 'width_mm' => 1830, 'sheet_type' => 'ملامینه', 'waste_percent' => 20, 'active' => true,
        ]);
    }

    /** The 3 mm back sheet: white HDF, 10% waste. */
    protected function back3(): StockItem
    {
        return StockItem::firstOrCreate(['code' => 'HDF-3-WH'], [
            'name' => 'سه میل سفید', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 3, 'color' => 'سفید',
            'length_mm' => 3660, 'width_mm' => 1830, 'sheet_type' => 'HDF', 'waste_percent' => 10, 'active' => true,
        ]);
    }

    /** An edge band item, made the body sheet's default band when asked. */
    protected function edgeBand(bool $onBody = true): StockItem
    {
        $band = StockItem::firstOrCreate(['code' => 'EB-WAL-2'], ['name' => 'نوار لبه گردویی ۲', 'kind' => 'edge_band', 'unit' => 'meter', 'active' => true]);
        if ($onBody) {
            $this->body16()->update(['default_edge_band_id' => $band->id]);
        }

        return $band;
    }

    /** The DAMTV220 TV unit from the workshop drawing, as an API payload (mm). */
    protected function damtv220Parts(): array
    {
        $body = $this->body16()->id;

        return [
            ['name' => 'صفحه رویی', 'length_mm' => 2000, 'width_mm' => 400, 'count' => 1, 'stock_item_id' => $body, 'edge_length' => 0, 'edge_width' => 0, 'ops' => ['edge_fold'], 'note' => 'لبه خم ۱۰ سانت'],
            ['name' => 'کف', 'length_mm' => 1967, 'width_mm' => 383, 'count' => 1, 'stock_item_id' => $body, 'edge_length' => 0, 'edge_width' => 0, 'ops' => ['edge_fold', 'cnc']],
            ['name' => 'درب ها', 'length_mm' => 283, 'width_mm' => 380, 'count' => 4, 'stock_item_id' => $body, 'edge_length' => 2, 'edge_width' => 0, 'grain_locked' => true, 'note' => 'دربها مگنتی'],
            ['name' => 'طبقه', 'length_mm' => 555, 'width_mm' => 383, 'count' => 1, 'stock_item_id' => $body, 'edge_length' => 0, 'edge_width' => 0],
            ['name' => 'بغل ها', 'length_mm' => 497, 'width_mm' => 384, 'count' => 2, 'stock_item_id' => $body, 'edge_length' => 0, 'edge_width' => 0],
            ['name' => 'وادارها', 'length_mm' => 218, 'width_mm' => 210, 'count' => 4, 'stock_item_id' => $body, 'edge_length' => 0, 'edge_width' => 0],
            ['name' => 'پشت بند', 'length_mm' => 1780, 'width_mm' => 230, 'count' => 1, 'stock_item_id' => $this->back3()->id, 'edge_length' => 0, 'edge_width' => 0],
        ];
    }

    /** Stores DAMTV220's parts on a product's default design (or a variation's) without going through the API. */
    protected function damtv220(?Product $product = null, ?Variation $variation = null): Product
    {
        $product ??= Product::factory()->create(['title' => 'میزتلویزیون DAMTV220', 'dimensions' => '200*40*40']);
        $this->damtv220On($this->design($product, $variation));

        return $product;
    }

    protected function damtv220On(ProductDesign $design): ProductDesign
    {
        foreach ($this->damtv220Parts() as $position => $part) {
            ProductPart::create($part + ['design_id' => $design->id, 'position' => $position]);
        }

        return $design;
    }
}
