<?php

namespace Tests\Feature\Production;

use App\Models\BomLine;
use App\Models\Image;
use App\Models\User;

class DesignSheetTest extends CuttingTestCase
{
    public function test_sheet_for_three_units()
    {
        $product = $this->damtv220();
        $design = $this->design($product);
        $hinge = $this->stock(10, ['code' => 'HNG', 'unit' => 'piece'], 50000);
        BomLine::create(['design_id' => $design->id, 'stock_item_id' => $hinge->id, 'quantity' => 4]);

        $r = $this->getJson("/api/v1/admin/designs/{$design->id}/sheet?quantity=3", $this->header('SuperAdmin'))->assertStatus(200);

        $r->assertJsonPath('design.name', 'استاندارد')
            ->assertJsonPath('product.title', 'میزتلویزیون DAMTV220')
            ->assertJsonPath('quantity', 3)
            ->assertJsonPath('groups.0.parts.2.name', 'درب ها')
            ->assertJsonPath('groups.0.parts.2.count', 12)
            ->assertJsonPath('summary.0.pieces', 39)
            ->assertJsonPath('bom_lines.0.stock_item.code', 'HNG')
            ->assertJsonPath('bom_lines.0.quantity', 12)
            ->assertJsonPath('product.image', null);
        $this->assertStringNotContainsString('cost', json_encode($r->json()));

        $this->getJson("/api/v1/admin/designs/{$design->id}/sheet", $this->header('Technic'))
            ->assertJsonPath('quantity', 1)->assertJsonPath('groups.0.parts.2.count', 4);
    }

    public function test_sheet_carries_the_product_main_image()
    {
        $product = $this->damtv220();
        $design = $product->designs()->first();
        $chosen = Image::factory()->create(['product_id' => $product->id, 'image' => 'products/front.jpg']);
        Image::factory()->create(['product_id' => $product->id, 'image' => 'products/side.jpg']);
        $product->update(['thumbnail_id' => $chosen->id]);

        $this->getJson("/api/v1/admin/designs/{$design->id}/sheet", $this->header('Technic'))
            ->assertStatus(200)
            ->assertJsonPath('product.image', config('app.url').'/storage/products/front.jpg');
    }

    public function test_quantity_bounds_and_permission()
    {
        $design = $this->design($this->damtv220());
        $url = "/api/v1/admin/designs/{$design->id}/sheet";

        $this->getJson("$url?quantity=0", $this->header('Technic'))->assertStatus(422);
        $this->getJson("$url?quantity=1001", $this->header('Technic'))->assertStatus(422);

        foreach (['orders.create', 'quotes.manage', 'production.view', 'bom.manage'] as $permission) {
            $user = User::factory()->create();
            $user->givePermissionTo($permission);
            $this->getJson($url, $this->header($user))->assertStatus(200);
        }
        $this->getJson($url, $this->header('Warehouse'))->assertStatus(403);
    }
}
