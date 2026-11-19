<?php

namespace Tests\Feature\Production;

use App\Services\Production\CuttingListService;
use App\Services\Production\Drafts\DimensionReading;
use App\Services\Production\Drafts\DraftException;
use App\Services\Production\Drafts\DraftMaterials;
use App\Services\Production\Drafts\TemplateRegistry;
use App\Models\ProductPart;
use Illuminate\Support\Facades\Validator;

class DesignTemplateTest extends CuttingTestCase
{
    private function draftMaterials(): DraftMaterials
    {
        return new DraftMaterials($this->body16(), $this->back3());
    }

    private function registry(): TemplateRegistry
    {
        return app(TemplateRegistry::class);
    }

    private function asModels(array $parts)
    {
        return collect($parts)->map(fn ($p) => (new ProductPart(CuttingListService::partAttributes($p, 0)))->setRelation('panelMaterial', null));
    }

    public function test_every_template_gives_valid_parts_that_fit_inside_its_size()
    {
        foreach ($this->registry()->all() as $key => $template) {
            foreach ([$template->defaults(), ['drawers' => 2, 'doors' => 1] + $template->defaults()] as $params) {
                $parts = $template->parts($params, $this->draftMaterials());
                $this->assertNotEmpty($parts, $key);

                $validator = Validator::make(['parts' => $parts], CuttingListService::partRules());
                $this->assertFalse($validator->fails(), $key . ': ' . json_encode($validator->errors()->all(), JSON_UNESCAPED_UNICODE));

                // A bed frame is wider than its mattress by design; every other template fits its overall size.
                if ($key !== 'bed') {
                    $p = $template->clamp($params);
                    $dimensions = "{$p['width']}*{$p['height']}*{$p['depth']}";
                    $this->assertSame([], app(CuttingListService::class)->fitWarnings($dimensions, $this->asModels($parts)), $key);
                }
            }
        }
    }

    public function test_cabinet_parts_follow_the_dimensions()
    {
        $cabinet = $this->registry()->get('cabinet');
        $parts = collect($cabinet->parts(['width' => 80, 'height' => 180, 'depth' => 40, 'shelves' => 3, 'doors' => 2, 'drawers' => 0, 'back' => 1], $this->draftMaterials()))
            ->keyBy('name')->map(fn ($p) => [$p['length_mm'], $p['width_mm'], $p['count'], $p['panel_material_id'], $p['edge_length'], $p['edge_width']])->all();

        $body = $this->body16()->id;
        $this->assertSame([
            'بغل' => [1800, 400, 2, $body, 1, 0],
            'سقف و کف' => [768, 400, 2, $body, 1, 0],
            'طبقه' => [768, 380, 3, $body, 1, 0],
            'درب' => [1794, 395, 2, $body, 2, 2],
            'پشت‌بند' => [796, 1796, 1, $this->back3()->id, 0, 0],
        ], $parts);
    }

    public function test_hardware_counts()
    {
        $r = $this->registry();
        // Doors taller than a metre get three hinges.
        $this->assertSame(['hinge' => 6, 'handle' => 2], $r->get('cabinet')->hardware(['width' => 80, 'height' => 180, 'depth' => 40, 'doors' => 2]));
        $this->assertSame(['hinge' => 4, 'handle' => 2], $r->get('low_unit')->hardware(['width' => 160, 'height' => 50, 'depth' => 40, 'doors' => 2]));
        $this->assertSame(['slide' => 3, 'handle' => 3], $r->get('drawer_unit')->hardware(['drawers' => 3]));
        $this->assertSame(['hinge' => 4, 'slide' => 2, 'handle' => 4, 'leg' => 4],
            $r->get('low_unit')->hardware(['width' => 160, 'height' => 60, 'depth' => 40, 'doors' => 2, 'drawers' => 2, 'legs' => 4]));
        $this->assertSame(['leg' => 4], $r->get('table')->hardware([]));
    }

    public function test_drawers_add_fronts_boxes_and_bottoms()
    {
        $parts = collect($this->registry()->get('drawer_unit')->parts(['width' => 100, 'height' => 80, 'depth' => 45, 'drawers' => 2, 'back' => 1], $this->draftMaterials()))->keyBy('name');

        $this->assertSame(2, $parts['جلو کشو']['count']);
        $this->assertSame(4, $parts['بغل کشو']['count']);
        $this->assertSame(4, $parts['پشت و جلوی داخلی کشو']['count']);
        $this->assertSame(2, $parts['کف کشو']['count']);
        $this->assertSame($this->back3()->id, $parts['کف کشو']['panel_material_id']);
        $this->assertArrayNotHasKey('درب', $parts->all());
    }

    public function test_suggest_takes_the_reading_and_sizes_the_counts()
    {
        $cabinet = $this->registry()->get('cabinet');
        $params = $cabinet->suggest(new DimensionReading(120, 195, 40, true));
        $this->assertSame(['width' => 120, 'height' => 195, 'depth' => 40], array_intersect_key($params, array_flip(['width', 'height', 'depth'])));
        $this->assertSame(4, $params['shelves']);

        // A bed reading has no height: the template's default fills it.
        $bed = $this->registry()->get('bed')->suggest(new DimensionReading(90, null, 200, true));
        $this->assertSame(35, $bed['height']);
    }

    public function test_impossible_counts_are_refused()
    {
        $this->expectException(DraftException::class);
        $this->registry()->get('drawer_unit')->parts(['width' => 100, 'height' => 20, 'depth' => 45, 'drawers' => 8], $this->draftMaterials());
    }

    public function test_template_list_has_keys_and_params()
    {
        $list = $this->registry()->present();
        $this->assertSame(['cabinet', 'low_unit', 'drawer_unit', 'desk', 'table', 'bed', 'wall_shelf', 'mirror'], array_column($list, 'key'));
        $this->assertSame('width', $list[0]['params'][0]['key']);
    }
}
