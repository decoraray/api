<?php

namespace Tests\Feature\Variations;

use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\User;
use App\Models\Variation;

class VariationDesignApiTest extends VariationTestCase
{
    private function setDesign(Variation $variation, $designId)
    {
        return $this->putJson("/api/v1/admin/variations/{$variation->id}", ['design_id' => $designId], $this->header('SuperAdmin'));
    }

    public function test_several_variations_can_share_a_design_and_clear_it()
    {
        $design = $this->unit();
        [$a, $b] = [Variation::factory()->create(['product_id' => $design->product_id]), Variation::factory()->create(['product_id' => $design->product_id])];

        $this->setDesign($a, $design->id)->assertStatus(200);
        $this->setDesign($b, $design->id)->assertStatus(200);
        $this->assertSame([$design->id, $design->id], [$a->fresh()->design_id, $b->fresh()->design_id]);
        $this->getJson("/api/v1/admin/variations/{$a->id}", $this->header('SuperAdmin'))
            ->assertJsonPath('data.design_id', $design->id)->assertJsonPath('data.design.name', $design->name);

        $this->setDesign($a, null)->assertStatus(200);
        $this->assertNull($a->fresh()->design_id);
    }

    public function test_only_an_active_design_of_the_same_product()
    {
        $design = $this->unit();
        $variation = $this->variationOf($design);
        $foreign = ProductDesign::factory()->create();
        $off = ProductDesign::factory()->create(['product_id' => $design->product_id, 'name' => 'خاموش', 'active' => false]);

        foreach ([$foreign->id, $off->id, 99999, 'abc'] as $bad) {
            $this->setDesign($variation, $bad)->assertStatus(422)->assertJsonValidationErrors('design_id');
        }
        $this->assertSame($design->id, $variation->fresh()->design_id);

        $this->postJson('/api/v1/admin/variations', ['product_id' => $design->product_id, 'color' => 'سفید', 'price' => 1, 'stock' => 1, 'weight' => 1, 'design_id' => $foreign->id], $this->header('SuperAdmin'))
            ->assertStatus(422);
    }

    public function test_deactivating_a_design_returns_its_variations_to_the_default()
    {
        $design = $this->unit();
        $own = ProductDesign::factory()->create(['product_id' => $design->product_id, 'name' => 'ویژه']);
        $variation = $this->variationOf($own);

        $this->putJson("/api/v1/admin/designs/{$own->id}", ['active' => false], $this->header('SuperAdmin'))->assertStatus(200);

        $this->assertNull($variation->fresh()->design_id);
    }

    public function test_saving_the_whole_list_keeps_designs_that_are_not_sent_and_sets_those_that_are()
    {
        $design = $this->unit();
        $product = $design->product;
        $other = ProductDesign::factory()->create(['product_id' => $product->id, 'name' => 'ویژه']);
        $url = "/api/v1/admin/products/{$product->id}/variations";
        $row = fn (string $size, array $extra = []) => ['colors' => ['سفید'], 'price' => 1000, 'size' => $size] + $extra;

        $this->postJson($url, [$row('160', ['design_id' => $design->id]), $row('180', ['design_id' => $other->id])], $this->header('SuperAdmin'))->assertStatus(200);
        $this->assertSame([$design->id, $other->id], $product->variations()->orderBy('size')->pluck('design_id')->all());

        // An older admin that sends no design keeps each variation's link, and a blank one clears it.
        $this->postJson($url, [$row('160'), $row('180', ['design_id' => ''])], $this->header('SuperAdmin'))->assertStatus(200);
        $this->assertSame([$design->id, null], $product->variations()->orderBy('size')->pluck('design_id')->all());

        $this->postJson($url, [$row('160', ['design_id' => ProductDesign::factory()->create()->id])], $this->header('SuperAdmin'))->assertStatus(422);
        $this->assertSame(2, $product->variations()->count());
    }

    public function test_material_sheets()
    {
        $material = $this->material('هایگلاس');
        $gloss = $this->gloss();
        $front = $this->stock(0, ['code' => 'MDF16-RAW1', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16]);
        $hinge = $this->stock(0);
        $url = "/api/v1/admin/materials/{$material->id}/sheets";
        $technic = $this->header('Technic');

        $this->putJson($url, ['body_sheet_item_id' => $gloss->id, 'front_sheet_item_id' => $front->id], $technic)->assertStatus(200)
            ->assertJsonPath('body_sheet.code', 'HIGLOSS16')->assertJsonPath('front_sheet.code', 'MDF16-RAW1');
        $r = $this->getJson('/api/v1/admin/materials', $this->header('SuperAdmin'))->assertStatus(200);
        $this->assertStringNotContainsString('average_cost', $r->getContent());
        $this->assertSame('HIGLOSS16', collect($r->json('data'))->firstWhere('id', $material->id)['body_sheet']['code']);

        $this->putJson($url, ['body_sheet_item_id' => null, 'front_sheet_item_id' => $front->id], $technic)->assertStatus(422)->assertJsonValidationErrors('body_sheet_item_id');
        $this->putJson($url, ['body_sheet_item_id' => $hinge->id, 'front_sheet_item_id' => null], $technic)->assertStatus(422)->assertJsonValidationErrors('body_sheet_item_id');
        $this->putJson($url, ['body_sheet_item_id' => null, 'front_sheet_item_id' => null], $technic)->assertStatus(200)->assertJsonPath('body_sheet', null);
        $this->assertNull($material->fresh()->body_sheet_item_id);

        $editor = User::factory()->create();
        $editor->givePermissionTo(['catalog.edit', 'catalog.view']);
        $this->putJson($url, ['body_sheet_item_id' => $gloss->id, 'front_sheet_item_id' => null], $this->header($editor))->assertStatus(403);
        // The ordinary material form cannot set sheets.
        $this->putJson("/api/v1/admin/materials/{$material->id}", ['name' => 'هایگلاس', 'body_sheet_item_id' => $gloss->id], $this->header($editor))->assertStatus(200);
        $this->assertNull($material->fresh()->body_sheet_item_id);
    }
}
