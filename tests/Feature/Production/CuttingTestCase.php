<?php

namespace Tests\Feature\Production;

use App\Models\PanelMaterial;
use App\Models\Product;
use App\Models\ProductPart;
use App\Models\Variation;
use App\Services\Production\CuttingListService;

abstract class CuttingTestCase extends MaterialTestCase
{
    protected function cutting(): CuttingListService
    {
        return app(CuttingListService::class);
    }

    protected function body16(): PanelMaterial
    {
        return PanelMaterial::where('name', 'بدنه ۱۶ میل')->firstOrFail();
    }

    protected function back3(): PanelMaterial
    {
        return PanelMaterial::where('name', 'پشت‌بند ۳ میل')->firstOrFail();
    }

    /** The DAMTV220 TV unit from the workshop drawing, as an API payload (mm). */
    protected function damtv220Parts(): array
    {
        $body = $this->body16()->id;

        return [
            ['name' => 'صفحه رویی', 'length_mm' => 2000, 'width_mm' => 400, 'count' => 1, 'panel_material_id' => $body, 'edge_length' => 0, 'edge_width' => 0, 'ops' => ['edge_fold'], 'note' => 'لبه خم ۱۰ سانت'],
            ['name' => 'کف', 'length_mm' => 1967, 'width_mm' => 383, 'count' => 1, 'panel_material_id' => $body, 'edge_length' => 0, 'edge_width' => 0, 'ops' => ['edge_fold', 'cnc']],
            ['name' => 'درب ها', 'length_mm' => 283, 'width_mm' => 380, 'count' => 4, 'panel_material_id' => $body, 'edge_length' => 2, 'edge_width' => 0, 'grain_locked' => true, 'note' => 'دربها مگنتی'],
            ['name' => 'طبقه', 'length_mm' => 555, 'width_mm' => 383, 'count' => 1, 'panel_material_id' => $body, 'edge_length' => 0, 'edge_width' => 0],
            ['name' => 'بغل ها', 'length_mm' => 497, 'width_mm' => 384, 'count' => 2, 'panel_material_id' => $body, 'edge_length' => 0, 'edge_width' => 0],
            ['name' => 'وادارها', 'length_mm' => 218, 'width_mm' => 210, 'count' => 4, 'panel_material_id' => $body, 'edge_length' => 0, 'edge_width' => 0],
            ['name' => 'پشت بند', 'length_mm' => 1780, 'width_mm' => 230, 'count' => 1, 'panel_material_id' => $this->back3()->id, 'edge_length' => 0, 'edge_width' => 0],
        ];
    }

    /** Stores DAMTV220's parts on a product (or one variation of it) without going through the API. */
    protected function damtv220(?Product $product = null, ?Variation $variation = null): Product
    {
        $product ??= Product::factory()->create(['title' => 'میزتلویزیون DAMTV220', 'dimensions' => '200*40*40']);

        foreach ($this->damtv220Parts() as $position => $part) {
            ProductPart::create($part + ['product_id' => $product->id, 'variation_id' => $variation?->id, 'position' => $position]);
        }

        return $product;
    }
}
