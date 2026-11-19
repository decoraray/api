<?php

namespace Tests\Feature\Production;

use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\Quote;
use App\Models\Shipping;
use App\Models\Variation;

class OrderLineDesignTest extends CuttingTestCase
{
    private function orderPayload(array $lines): array
    {
        return [
            'phone' => '09120000000',
            'customer_name' => 'علی رضایی',
            'lines' => $lines,
            'address' => 'تهران',
            'shipping_id' => Shipping::factory()->create(['additional_percent' => 0])->id,
            'payment_method' => 'CARD',
            'status' => 'CONFIRMED',
        ];
    }

    public function test_staff_order_line_with_a_design_feeds_its_work_order()
    {
        $variation = Variation::factory()->create(['price' => 1000000]);
        $this->damtv220($variation->product);
        $small = $this->damtv220On(ProductDesign::factory()->create(['product_id' => $variation->product_id, 'name' => '۱۸۰ سانتی']));
        $small->parts()->where('position', '>', 1)->delete();

        $r = $this->postJson('/api/v1/admin/orders', $this->orderPayload([['variation_id' => $variation->id, 'quantity' => 1, 'design_id' => $small->id]]), $this->header('SuperAdmin'))
            ->assertStatus(201);

        $item = Item::where('order_id', $r->json('data.id') ?? Order::latest('id')->value('id'))->sole();
        $this->assertSame($small->id, $item->design_id);
        $this->assertSame(['۱۸۰ سانتی', 2], [$item->workOrder->design_name, $item->workOrder->parts()->count()]);

        $this->getJson('/api/v1/admin/orders/' . $item->order_id, $this->header('Sales'))
            ->assertJsonPath('data.items.0.design.name', '۱۸۰ سانتی');
    }

    public function test_design_of_another_product_is_refused()
    {
        $variation = Variation::factory()->create(['price' => 1000000]);
        $foreign = ProductDesign::factory()->create();
        $inactive = ProductDesign::factory()->create(['product_id' => $variation->product_id, 'active' => false]);

        foreach ([$foreign, $inactive] as $design) {
            $this->postJson('/api/v1/admin/orders', $this->orderPayload([['variation_id' => $variation->id, 'quantity' => 1, 'design_id' => $design->id]]), $this->header('SuperAdmin'))
                ->assertStatus(422)->assertJsonValidationErrors('lines.0.design_id');
        }
        $this->assertSame(0, Order::count());
    }

    public function test_custom_line_with_a_one_off_design()
    {
        $oneOff = Product::factory()->create(['active' => false]);
        $oneOff->forceFill(['one_off' => true])->save();
        $design = $this->damtv220On(ProductDesign::factory()->create(['product_id' => $oneOff->id]));

        $this->postJson('/api/v1/admin/orders', $this->orderPayload([['title' => 'جلومبلی', 'unit_price' => 5000000, 'quantity' => 1, 'design_id' => $design->id]]), $this->header('SuperAdmin'))
            ->assertStatus(201);

        $item = Item::sole();
        $this->assertNull($item->product_id);
        $this->assertSame(7, $item->workOrder->parts()->count());
    }

    public function test_quote_line_design_reaches_the_order_but_not_the_customer()
    {
        $variation = Variation::factory()->create();
        $design = $this->damtv220On(ProductDesign::factory()->create(['product_id' => $variation->product_id, 'name' => '۱۸۰ سانتی']));
        $headers = $this->header('SuperAdmin');
        $line = ['product_id' => $variation->product_id, 'variation_id' => $variation->id, 'description' => 'میز تلویزیون', 'quantity' => 1, 'unit_price' => 1000, 'design_id' => $design->id];
        $payload = ['customer_name' => 'علی', 'phone' => '09123456789', 'shipping_mode' => 'on_delivery', 'lines' => [$line]];

        $this->postJson('/api/v1/admin/quotes', ['lines' => [['design_id' => ProductDesign::factory()->create()->id] + $line]] + $payload, $headers)
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.design_id');

        $r = $this->postJson('/api/v1/admin/quotes', $payload, $headers)->assertStatus(201)->assertJsonPath('data.lines.0.design.name', '۱۸۰ سانتی');
        $quote = Quote::findOrFail($r->json('data.id'));

        $public = $this->getJson('/api/v1/quotes/' . $quote->token)->assertStatus(200)->json();
        $this->assertStringNotContainsString('design', json_encode($public));
        $this->assertStringNotContainsString('۱۸۰ سانتی', json_encode($public, JSON_UNESCAPED_UNICODE));

        $this->postJson("/api/v1/admin/quotes/{$quote->id}/payments", ['amount' => 100, 'method' => 'CASH_RECEIVED', 'paid_at' => '2026-11-15'], $headers)->assertStatus(201);
        $order = $quote->fresh()->order;
        $this->assertSame($design->id, $order->items()->sole()->design_id);

        $workOrder = $this->service()->createForOrder($order)->sole();
        $this->assertSame(['۱۸۰ سانتی', 7], [$workOrder->design_name, $workOrder->parts()->count()]);
    }
}
