<?php

namespace Tests\Feature\Production;

use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\User;
use App\Models\Variation;
use Spatie\Activitylog\Models\Activity;

class SaveWorkOrderDesignTest extends CuttingTestCase
{
    public function test_save_a_customised_work_order_as_a_new_size()
    {
        $variation = Variation::factory()->create();
        $this->damtv220($variation->product);
        $hinge = $this->stock(20);
        $workOrder = $this->workOrderFor($variation, 2);
        // Customised: its parts saved again (sheets follow them, for 2 units) and 8 hinges typed in.
        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/parts", ['parts' => $this->damtv220Parts()], $this->header('Technic'))->assertStatus(200);
        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/materials", ['lines' => [['stock_item_id' => $hinge->id, 'quantity' => 8]]], $this->header('Technic'))->assertStatus(200);
        $this->assertTrue($workOrder->fresh()->customized);

        $r = $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/save-as-design", ['mode' => 'new', 'name' => '۲۰۰ سانتی'], $this->header('Technic'))
            ->assertStatus(201)->assertJsonPath('data.name', '۲۰۰ سانتی')->assertJsonPath('data.parts_count', 7);

        $design = ProductDesign::findOrFail($r->json('data.id'));
        $this->assertSame($variation->product_id, $design->product_id);
        $this->assertFalse($design->is_default);
        $this->assertSame($workOrder->id, $design->source_work_order_id);
        // Hinges per unit; the sheets calculated again from the parts for one unit.
        $this->assertEqualsCanonicalizing([[$hinge->id, 4.0], [$this->body16()->id, 0.5], [$this->back3()->id, 0.07]],
            $design->bomLines->map(fn ($l) => [$l->stock_item_id, $l->quantity])->all());

        $workOrder->refresh();
        $this->assertSame([$design->id, '۲۰۰ سانتی', false], [$workOrder->design_id, $workOrder->design_name, $workOrder->customized]);
        $this->assertTrue(Activity::where('description', 'design-saved-from-work-order')->where('subject_id', $design->id)->exists());

        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/save-as-design", ['mode' => 'new', 'name' => '۲۰۰ سانتی'], $this->header('Technic'))
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_overwrite_leaves_other_work_orders_alone()
    {
        $variation = Variation::factory()->create();
        $this->damtv220($variation->product);
        $standard = $this->design($variation->product);
        $first = $this->workOrderFor($variation);
        $second = $this->workOrderFor($variation);
        $this->putJson("/api/v1/admin/work-orders/{$first->id}/parts", ['parts' => array_slice($this->damtv220Parts(), 0, 2)], $this->header('Technic'));

        $this->postJson("/api/v1/admin/work-orders/{$first->id}/save-as-design", ['mode' => 'overwrite', 'design_id' => $standard->id], $this->header('Technic'))
            ->assertStatus(200);

        $this->assertSame(2, $standard->parts()->count());
        $this->assertSame(7, $second->parts()->count());

        $foreign = ProductDesign::factory()->create();
        $this->postJson("/api/v1/admin/work-orders/{$first->id}/save-as-design", ['mode' => 'overwrite', 'design_id' => $foreign->id], $this->header('Technic'))
            ->assertStatus(422)->assertJsonValidationErrors('design_id');
    }

    public function test_keep_a_custom_piece_as_a_one_off_product()
    {
        $workOrder = $this->workOrderFor(null);
        $workOrder->update(['number' => 'WO-77-1']);
        $this->putJson("/api/v1/admin/work-orders/{$workOrder->id}/parts", ['parts' => array_slice($this->damtv220Parts(), 0, 4)], $this->header('Technic'));
        Product::factory()->create(['slug' => 'one-off-wo-77-1']);

        $r = $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/save-as-design", [
            'mode' => 'new', 'name' => 'استاندارد', 'new_product_title' => 'جلومبلی سفارشی - خانم احمدی',
        ], $this->header('Technic'))->assertStatus(201)->assertJsonPath('product.one_off', true);

        $product = Product::findOrFail($r->json('product.id'));
        $this->assertSame(['جلومبلی سفارشی - خانم احمدی', 'one-off-wo-77-1-2', false, true], [$product->title, $product->slug, (bool) $product->active, $product->one_off]);
        $design = $product->designs()->sole();
        $this->assertTrue($design->is_default);
        $this->assertSame(4, $design->parts()->count());
        $this->assertNull($workOrder->item->fresh()->product_id, 'the order line stays a custom line');
    }

    public function test_custom_line_onto_an_existing_product_and_catalog_line_rules()
    {
        $custom = $this->workOrderFor(null);
        $target = Product::factory()->create();
        $this->postJson("/api/v1/admin/work-orders/{$custom->id}/save-as-design", ['mode' => 'new', 'name' => 'x', 'product_id' => $target->id], $this->header('Technic'))
            ->assertStatus(201);
        $this->assertSame(1, $target->designs()->count());

        $this->postJson("/api/v1/admin/work-orders/{$custom->id}/save-as-design", ['mode' => 'new', 'name' => 'y'], $this->header('Technic'))
            ->assertStatus(422)->assertJsonValidationErrors('product_id');

        $catalog = $this->workOrderFor(Variation::factory()->create());
        $this->postJson("/api/v1/admin/work-orders/{$catalog->id}/save-as-design", ['mode' => 'new', 'name' => 'x', 'product_id' => $target->id], $this->header('Technic'))
            ->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_needs_bom_manage()
    {
        $workOrder = $this->workOrderFor(null);
        $user = User::factory()->create();
        $user->givePermissionTo(['production.view', 'production.update']);

        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/save-as-design", ['mode' => 'new', 'name' => 'x', 'new_product_title' => 't'], $this->header($user))
            ->assertStatus(403);
        $this->assertSame(0, ProductDesign::count());
    }
}
