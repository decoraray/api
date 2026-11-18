<?php

namespace Tests\Feature\Production;

use App\Enums\OrderStatus;
use App\Enums\ProductionStage;
use App\Models\Variation;

class WorkOrderMaterialApiTest extends MaterialTestCase
{
    public function test_confirming_an_order_fills_the_material_list_from_the_bom()
    {
        $variation = Variation::factory()->create();
        $sheet = $this->stock(10);
        $hinge = $this->stock(50);
        $this->bom($variation->product, [[$sheet, 1.5], [$hinge, 4]]);
        $order = \App\Models\Order::factory()->create(['status' => OrderStatus::PAID->value]);
        \App\Models\Item::create(['title' => 'میز', 'slug' => 'x', 'price' => 1, 'quantity' => 2, 'product_id' => $variation->product_id, 'variation_id' => $variation->id, 'order_id' => $order->id]);

        $this->patchJson("/api/v1/admin/orders/{$order->uuid}", ['status' => 'CONFIRMED'], $this->header('Sales'))->assertStatus(200);

        $workOrder = $order->workOrders()->firstOrFail();
        $r = $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}/materials", $this->header('Sales'))->assertStatus(200);
        $r->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.stock_item.id', $sheet->id)
            ->assertJsonPath('data.0.planned_quantity', 3)
            ->assertJsonPath('data.0.reserved_quantity', 0)
            ->assertJsonPath('data.0.unreserved_quantity', 3)
            ->assertJsonPath('data.0.missing_quantity', 0)
            ->assertJsonPath('data.1.planned_quantity', 8);
    }

    public function test_edit_reserve_consume_and_release()
    {
        $sheet = $this->stock(10, [], 1100000);
        $hinge = $this->stock(5, [], 50000);
        $workOrder = $this->workOrderFor(null);
        $base = "/api/v1/admin/work-orders/{$workOrder->id}/materials";
        $headers = $this->header('Technic');

        $this->putJson($base, ['lines' => [
            ['stock_item_id' => $sheet->id, 'quantity' => 3],
            ['stock_item_id' => $hinge->id, 'quantity' => 8],
        ]], $headers)->assertStatus(200)->assertJsonCount(2, 'data');

        // reserve: the sheet is covered, the hinges are 3 short
        $r = $this->postJson("{$base}/reserve", [], $headers)->assertStatus(200);
        $r->assertJsonPath('data.0.reserved_quantity', 3)->assertJsonPath('data.0.missing_quantity', 0)
            ->assertJsonPath('data.1.reserved_quantity', 5)->assertJsonPath('data.1.unreserved_quantity', 3)->assertJsonPath('data.1.missing_quantity', 3);

        $line = $r->json('data.0.id');
        $r = $this->postJson("{$base}/{$line}/consume", ['quantity' => 2, 'waste_quantity' => 0.5], $headers)->assertStatus(200);
        $r->assertJsonPath('data.0.consumed_quantity', 2)->assertJsonPath('data.0.wasted_quantity', 0.5)->assertJsonPath('data.0.reserved_quantity', 0.5);
        $this->assertSame(7.5, $sheet->refresh()->quantity);

        $this->postJson("{$base}/release", [], $headers)->assertStatus(200)
            ->assertJsonPath('data.0.reserved_quantity', 0)->assertJsonPath('data.1.reserved_quantity', 0);
        $this->assertSame([0.0, 0.0], [$sheet->refresh()->reserved, $hinge->refresh()->reserved]);

        // no cost anywhere, even for a user who may see costs
        foreach ([$headers, $this->header('SuperAdmin')] as $who) {
            $json = json_encode($this->getJson($base, $who)->json());
            $this->assertStringNotContainsString('cost', $json);
            $this->assertStringNotContainsString('1100000', $json);
        }
    }

    public function test_invalid_requests_are_rejected()
    {
        $sheet = $this->stock(4);
        [$other] = $this->workOrderNeeding($sheet, 4);
        $this->materials()->reserve($other);
        [$workOrder, $material] = $this->workOrderNeeding($sheet, 1);
        $base = "/api/v1/admin/work-orders/{$workOrder->id}/materials";
        $headers = $this->header('Technic');

        $this->postJson("{$base}/{$material->id}/consume", [], $headers)->assertStatus(422);
        $this->postJson("{$base}/{$material->id}/consume", ['quantity' => 0], $headers)->assertStatus(422);
        $this->postJson("{$base}/{$material->id}/consume", ['quantity' => -1], $headers)->assertStatus(422);
        // every sheet is held for the other job
        $this->postJson("{$base}/{$material->id}/consume", ['quantity' => 1], $headers)->assertStatus(422);
        // a line of another work order
        $foreign = $other->materials()->firstOrFail();
        $this->postJson("{$base}/{$foreign->id}/consume", ['quantity' => 1], $headers)->assertStatus(404);
        // the same item twice
        $this->putJson($base, ['lines' => [['stock_item_id' => $sheet->id, 'quantity' => 1], ['stock_item_id' => $sheet->id, 'quantity' => 1]]], $headers)->assertStatus(422);

        $this->assertSame(4.0, $sheet->refresh()->quantity);

        // cancelled: no consumption, no edits, no reservation
        $this->service()->cancelForOrder($workOrder->order);
        $this->postJson("{$base}/{$material->id}/consume", ['quantity' => 1], $headers)->assertStatus(422);
        $this->putJson($base, ['lines' => []], $headers)->assertStatus(422);
        $this->postJson("{$base}/reserve", [], $headers)->assertStatus(422);
    }

    public function test_completing_through_the_api_releases_the_reservation()
    {
        $sheet = $this->stock(10);
        [$workOrder] = $this->workOrderNeeding($sheet, 3);
        $headers = $this->header('Technic');
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/materials/reserve", [], $headers)->assertStatus(200);
        $this->service()->moveToStage($workOrder, ProductionStage::PACKAGING, null);

        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/complete", [], $headers)->assertStatus(200);

        $this->assertSame(0.0, $sheet->refresh()->reserved);
    }

    public function test_shortage_list()
    {
        $hinge = $this->stock(6, ['name' => 'لولا', 'code' => 'HNG-01']);
        [$first] = $this->workOrderNeeding($hinge, 5);
        [$second] = $this->workOrderNeeding($hinge, 4);
        $this->workOrderNeeding($this->stock(10), 4);

        $r = $this->getJson('/api/v1/admin/material-shortages', $this->header('Sales'))->assertStatus(200);

        $r->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.stock_item.code', 'HNG-01')
            ->assertJsonPath('data.0.need', 9)
            ->assertJsonPath('data.0.available', 6)
            ->assertJsonPath('data.0.missing', 3)
            ->assertJsonCount(2, 'data.0.work_orders')
            ->assertJsonPath('data.0.work_orders.0.number', $first->number);
        $this->assertStringNotContainsString('cost', json_encode($r->json()));
    }

    public function test_stock_item_shows_on_hand_reserved_and_available()
    {
        $sheet = $this->stock(10);
        [$workOrder] = $this->workOrderNeeding($sheet, 3);
        $this->materials()->reserve($workOrder);

        $this->getJson("/api/v1/admin/stock-items/{$sheet->id}", $this->header('Warehouse'))->assertStatus(200)
            ->assertJsonPath('data.quantity', 10)->assertJsonPath('data.reserved', 3)->assertJsonPath('data.available', 7);

        // the manual issue endpoint respects the hold
        $this->postJson("/api/v1/admin/stock-items/{$sheet->id}/movements", ['type' => 'out', 'quantity' => 8, 'note' => 'x'], $this->header('Warehouse'))->assertStatus(422);
    }

    public function test_view_only_and_outsiders()
    {
        $sheet = $this->stock(10);
        [$workOrder, $material] = $this->workOrderNeeding($sheet, 3);
        $base = "/api/v1/admin/work-orders/{$workOrder->id}/materials";
        $sales = $this->header('Sales');

        $this->getJson($base, $sales)->assertStatus(200);
        $this->putJson($base, ['lines' => []], $sales)->assertStatus(403);
        $this->postJson("{$base}/reserve", [], $sales)->assertStatus(403);
        $this->postJson("{$base}/release", [], $sales)->assertStatus(403);
        $this->postJson("{$base}/{$material->id}/consume", ['quantity' => 1], $sales)->assertStatus(403);
        $this->assertSame([10.0, 0.0], [$sheet->refresh()->quantity, $sheet->reserved]);

        foreach (['Warehouse', 'Admin'] as $role) {
            $this->getJson($base, $this->header($role))->assertStatus(403);
            $this->getJson('/api/v1/admin/material-shortages', $this->header($role))->assertStatus(403);
        }
    }
}
