<?php

namespace Tests\Feature\Production;

use App\Models\BomLine;

class CuttingListBomLinesTest extends CuttingTestCase
{
    public function test_proposes_lines_and_saves_nothing()
    {
        $product = $this->damtv220();
        $walnut = $this->stock(0, ['code' => 'MDF-16-WAL', 'kind' => 'mdf_sheet', 'unit' => 'sheet']);
        $band = $this->stock(0, ['code' => 'EB-WAL-2', 'kind' => 'edge_band', 'unit' => 'meter']);
        $hdf = $this->stock(0, ['code' => 'HDF-3-WH', 'kind' => 'mdf_sheet', 'unit' => 'sheet']);
        $hinge = $this->stock(0);
        $this->bom($product, [[$hinge, 4]]);

        $r = $this->postJson("/api/v1/admin/products/{$product->id}/cutting-list/bom-lines", ['mapping' => [
            ['panel_material_id' => $this->body16()->id, 'sheet_stock_item_id' => $walnut->id, 'edge_band_stock_item_id' => $band->id],
            ['panel_material_id' => $this->back3()->id, 'sheet_stock_item_id' => $hdf->id],
        ]], $this->header('SuperAdmin'))->assertStatus(200);

        $this->assertSame([['MDF-16-WAL', 0.5], ['EB-WAL-2', 2.6], ['HDF-3-WH', 0.07]],
            array_map(fn ($l) => [$l['stock_item']['code'], $l['quantity']], $r->json('data')));
        $r->assertJsonPath('data.1.stock_item.unit_label', 'متر');
        $this->assertStringNotContainsString('cost', json_encode($r->json()));

        $this->assertSame([$hinge->id], BomLine::pluck('stock_item_id')->all());
    }

    public function test_a_sheet_item_counted_in_square_metres_gets_the_area_with_waste()
    {
        $product = $this->damtv220();
        $m2 = $this->stock(0, ['code' => 'MDF-16-M2', 'kind' => 'mdf_sheet', 'unit' => 'square_metre']);
        $hdf = $this->stock(0, ['code' => 'HDF-3-WH', 'kind' => 'mdf_sheet', 'unit' => 'sheet']);

        $r = $this->postJson("/api/v1/admin/products/{$product->id}/cutting-list/bom-lines", ['mapping' => [
            ['panel_material_id' => $this->body16()->id, 'sheet_stock_item_id' => $m2->id],
            ['panel_material_id' => $this->back3()->id, 'sheet_stock_item_id' => $hdf->id],
        ]], $this->header('SuperAdmin'))->assertStatus(200);

        // 2.761 m² of 16 mm parts with 20% waste = 3.3132, rounded up to 3.32 m²; the HDF stays in sheets.
        $this->assertSame([['MDF-16-M2', 3.32], ['HDF-3-WH', 0.07]],
            array_map(fn ($l) => [$l['stock_item']['code'], $l['quantity']], $r->json('data')));
        $r->assertJsonPath('data.0.stock_item.unit_label', 'متر مربع');
    }

    public function test_wrong_kind_of_stock_item_is_rejected()
    {
        $product = $this->damtv220();
        $hinge = $this->stock(0);
        $sheet = $this->stock(0, ['kind' => 'mdf_sheet', 'unit' => 'sheet']);
        $url = "/api/v1/admin/products/{$product->id}/cutting-list/bom-lines";
        $headers = $this->header('Technic');

        $this->postJson($url, ['mapping' => [
            ['panel_material_id' => $this->body16()->id, 'sheet_stock_item_id' => $hinge->id],
            ['panel_material_id' => $this->back3()->id, 'sheet_stock_item_id' => $sheet->id],
        ]], $headers)->assertStatus(422)->assertJsonValidationErrors('mapping.0.sheet_stock_item_id');

        $this->postJson($url, ['mapping' => [
            ['panel_material_id' => $this->body16()->id, 'sheet_stock_item_id' => $sheet->id, 'edge_band_stock_item_id' => $sheet->id],
            ['panel_material_id' => $this->back3()->id, 'sheet_stock_item_id' => $sheet->id],
        ]], $headers)->assertStatus(422)->assertJsonValidationErrors('mapping.0.edge_band_stock_item_id');
    }

    public function test_missing_mapping_names_the_material()
    {
        $product = $this->damtv220();
        $sheet = $this->stock(0, ['kind' => 'mdf_sheet', 'unit' => 'sheet']);

        $r = $this->postJson("/api/v1/admin/products/{$product->id}/cutting-list/bom-lines", ['mapping' => [
            ['panel_material_id' => $this->body16()->id, 'sheet_stock_item_id' => $sheet->id],
        ]], $this->header('Technic'))->assertStatus(422)->assertJsonValidationErrors('mapping');

        $this->assertStringContainsString('سه میل سفید', $r->json('errors.mapping.0'));
    }

    public function test_requires_bom_manage()
    {
        $product = $this->damtv220();

        $this->postJson("/api/v1/admin/products/{$product->id}/cutting-list/bom-lines", ['mapping' => []], $this->header('Sales'))
            ->assertStatus(403);
    }
}
