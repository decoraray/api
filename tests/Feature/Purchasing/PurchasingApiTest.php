<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseOrderState;
use App\Enums\PurchaseRequestState;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class PurchasingApiTest extends PurchasingTestCase
{
    // --- suppliers --------------------------------------------------------------

    public function test_supplier_create_edit_deactivate_and_list()
    {
        $buyer = $this->staff('Purchasing');
        $headers = $this->header($buyer);

        $id = $this->postJson('/api/v1/admin/suppliers', ['name' => 'ام‌دی‌اف پارس', 'phone' => '02166000000'], $headers)
            ->assertStatus(201)->assertJsonPath('data.active', true)->assertJsonPath('data.score_count', 0)->json('data.id');

        $this->putJson("/api/v1/admin/suppliers/{$id}", ['name' => 'ام‌دی‌اف پارس', 'active' => false], $headers)
            ->assertStatus(200)->assertJsonPath('data.active', false);

        $this->assertCount(0, $this->getJson('/api/v1/admin/suppliers', $headers)->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/suppliers?active=all', $headers)->json('data'));

        $activity = Activity::where('subject_type', Supplier::class)->where('event', 'updated')->firstOrFail();
        $this->assertSame($buyer->id, $activity->causer_id);
    }

    public function test_supplier_permissions()
    {
        $this->getJson('/api/v1/admin/suppliers', $this->header('Warehouse'))->assertStatus(403);
        $this->getJson('/api/v1/admin/suppliers', $this->header('Sales'))->assertStatus(403);
        $this->postJson('/api/v1/admin/suppliers', ['name' => 'x'], $this->header('Warehouse'))->assertStatus(403);
        $this->assertSame(0, Supplier::count());
    }

    // --- requests ---------------------------------------------------------------

    public function test_warehouse_and_technic_request_and_requests_list_open_first()
    {
        $sheet = $this->stocked(4, 0, ['reorder_point' => 10]);
        $warehouse = $this->staff('Warehouse');

        $done = $this->postJson('/api/v1/admin/purchase-requests', ['stock_item_id' => $sheet->id, 'quantity' => 5], $this->header('Technic'))->assertStatus(201)->json('data.id');
        PurchaseRequest::whereKey($done)->update(['state' => 'cancelled']);
        $this->postJson('/api/v1/admin/purchase-requests', ['stock_item_id' => $sheet->id, 'quantity' => 20, 'note' => 'کمبود'], $this->header($warehouse))
            ->assertStatus(201)
            ->assertJsonPath('data.state', 'open')
            ->assertJsonPath('data.quantity', 20)
            ->assertJsonPath('data.requester.id', $warehouse->id);

        $r = $this->getJson('/api/v1/admin/purchase-requests', $this->header('Technic'))->assertStatus(200);
        $this->assertSame(['open', 'cancelled'], array_column($r->json('data'), 'state'));
        $this->assertStringNotContainsString('price', json_encode($r->json()));

        $this->postJson('/api/v1/admin/purchase-requests', ['stock_item_id' => $sheet->id, 'quantity' => 0], $this->header($warehouse))->assertStatus(422);
        $this->getJson('/api/v1/admin/purchase-requests', $this->header('Sales'))->assertStatus(403);
        $this->postJson('/api/v1/admin/purchase-requests', ['stock_item_id' => $sheet->id, 'quantity' => 1], $this->header('Sales'))->assertStatus(403);
    }

    public function test_who_may_cancel_a_request()
    {
        $sheet = $this->stocked(0);
        $requester = $this->staff('Warehouse');
        $mine = $this->request($sheet, 5, $requester);
        $theirs = $this->request($sheet, 5, $this->staff('Technic'));
        $managed = $this->request($sheet, 5);

        $this->postJson("/api/v1/admin/purchase-requests/{$theirs->id}/cancel", [], $this->header($requester))->assertStatus(403);
        $this->postJson("/api/v1/admin/purchase-requests/{$mine->id}/cancel", [], $this->header($requester))->assertStatus(200)->assertJsonPath('data.state', 'cancelled');
        $this->postJson("/api/v1/admin/purchase-requests/{$managed->id}/cancel", [], $this->header('Purchasing'))->assertStatus(200);

        $ordered = $this->request($sheet, 5);
        $this->placed([[$sheet, 5, 1, $ordered]]);
        $this->postJson("/api/v1/admin/purchase-requests/{$ordered->id}/cancel", [], $this->header('Purchasing'))->assertStatus(422);
        $this->assertSame(PurchaseRequestState::ORDERED, $ordered->fresh()->state);
    }

    // --- orders -----------------------------------------------------------------

    private function payload(array $over = []): array
    {
        $this->sheetForPayload ??= $this->stocked(0);
        $this->supplierForPayload ??= $this->supplier();

        return array_merge([
            'supplier_id' => $this->supplierForPayload->id,
            'expected_at' => '2026-11-25',
            'lines' => [['stock_item_id' => $this->sheetForPayload->id, 'quantity' => 20, 'unit_price' => 1200000]],
        ], $over);
    }

    private $sheetForPayload = null;
    private $supplierForPayload = null;

    public function test_create_edit_place_and_audit()
    {
        $buyer = $this->staff('Purchasing');
        $headers = $this->header($buyer);

        $r = $this->postJson('/api/v1/admin/purchase-orders', $this->payload(), $headers)->assertStatus(201);
        $id = $r->json('data.id');
        $r->assertJsonPath('data.state', 'draft')
            ->assertJsonPath('data.number', "PO-{$id}")
            ->assertJsonPath('data.total', 24000000)
            ->assertJsonPath('data.expected_at', '2026-11-25')
            ->assertJsonPath('data.lines.0.unit_price', 1200000)
            ->assertJsonPath('data.lines.0.outstanding', 20);

        $this->putJson("/api/v1/admin/purchase-orders/{$id}", $this->payload(['lines' => [['stock_item_id' => $this->sheetForPayload->id, 'quantity' => 10, 'unit_price' => 1100000]]]), $headers)
            ->assertStatus(200)->assertJsonPath('data.total', 11000000);

        $this->postJson("/api/v1/admin/purchase-orders/{$id}/place", [], $headers)->assertStatus(200)->assertJsonPath('data.state', 'placed');
        $this->putJson("/api/v1/admin/purchase-orders/{$id}", $this->payload(), $headers)->assertStatus(422);
        $this->postJson("/api/v1/admin/purchase-orders/{$id}/place", [], $headers)->assertStatus(422);

        $activity = Activity::where('subject_type', PurchaseOrder::class)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($buyer->id, $activity->causer_id);
        $this->assertSame('placed', $activity->properties['attributes']['state']);
    }

    public function test_invalid_orders_are_refused()
    {
        $headers = $this->header('Purchasing');
        $sheet = $this->stocked(0);

        $this->postJson('/api/v1/admin/purchase-orders', $this->payload(['lines' => []]), $headers)->assertStatus(422);
        $this->postJson('/api/v1/admin/purchase-orders', $this->payload(['lines' => [
            ['stock_item_id' => $sheet->id, 'quantity' => 1, 'unit_price' => 1],
            ['stock_item_id' => $sheet->id, 'quantity' => 2, 'unit_price' => 1],
        ]]), $headers)->assertStatus(422);
        $this->postJson('/api/v1/admin/purchase-orders', $this->payload(['lines' => [['stock_item_id' => $sheet->id, 'quantity' => 0, 'unit_price' => 1]]]), $headers)->assertStatus(422);
        $this->postJson('/api/v1/admin/purchase-orders', $this->payload(['supplier_id' => $this->supplier(['active' => false])->id]), $headers)->assertStatus(422);

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_list_filters_and_search()
    {
        $headers = $this->header('Purchasing');
        $sheet = $this->stocked(0);
        $a = $this->supplier();
        $b = $this->supplier();
        $first = $this->draft([[$sheet, 1, 1]], $a);
        $second = $this->placed([[$sheet, 1, 1]], $b);

        $ids = fn (string $q) => array_column($this->getJson("/api/v1/admin/purchase-orders?{$q}", $headers)->assertStatus(200)->json('data'), 'id');

        $this->assertSame([$second->id, $first->id], $ids(''));
        $this->assertSame([$first->id], $ids("supplier_id={$a->id}"));
        $this->assertSame([$second->id], $ids('state=placed'));
        $this->assertSame([$first->id], $ids('search=' . $first->number));
    }

    public function test_warehouse_receives_without_ever_seeing_prices()
    {
        $sheet = $this->stocked(0);
        $draft = $this->draft([[$sheet, 5, 1200000]]);
        $order = $this->placed([[$sheet, 20, 1200000]]);
        $warehouse = $this->staff('Warehouse');
        $headers = $this->header($warehouse);

        $list = $this->getJson('/api/v1/admin/purchase-orders', $headers)->assertStatus(200);
        $this->assertSame([$order->id], array_column($list->json('data'), 'id'));
        $this->getJson("/api/v1/admin/purchase-orders/{$draft->id}", $headers)->assertStatus(404);

        $show = $this->getJson("/api/v1/admin/purchase-orders/{$order->id}", $headers)->assertStatus(200);
        $show->assertJsonPath('data.lines.0.outstanding', 20);
        foreach ([$list, $show] as $r) {
            // the order data only: pagination meta has its own `total`
            $json = json_encode($r->json('data'));
            foreach (['price', 'total', '1200000', 'score'] as $hidden) {
                $this->assertStringNotContainsString($hidden, $json);
            }
        }

        $lineId = $show->json('data.lines.0.id');
        // a price in the payload is not a field the endpoint knows: the order's price is used
        $r = $this->postJson("/api/v1/admin/purchase-orders/{$order->id}/receipts", ['lines' => [['purchase_order_line_id' => $lineId, 'quantity' => 12, 'unit_price' => 1]], 'note' => 'بار اول'], $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.state', 'partially_received')
            ->assertJsonPath('data.lines.0.received_quantity', 12)
            ->assertJsonPath('data.lines.0.outstanding', 8)
            ->assertJsonPath('data.receipts.0.received_by.id', $warehouse->id)
            ->assertJsonPath('data.receipts.0.note', 'بار اول');
        $this->assertStringNotContainsString('price', json_encode($r->json()));
        $this->assertSame(1200000, $sheet->refresh()->average_cost);

        $this->postJson("/api/v1/admin/purchase-orders/{$order->id}/receipts", ['lines' => [['purchase_order_line_id' => $lineId, 'quantity' => 10]]], $headers)->assertStatus(422);
        $this->postJson("/api/v1/admin/purchase-orders/{$order->id}/receipts", ['lines' => []], $headers)->assertStatus(422);
        $this->postJson("/api/v1/admin/purchase-orders/{$order->id}/receipts", ['lines' => [['purchase_order_line_id' => 99999, 'quantity' => 1]]], $headers)->assertStatus(422);
        $this->postJson("/api/v1/admin/purchase-orders/{$draft->id}/receipts", ['lines' => [['purchase_order_line_id' => $draft->lines()->first()->id, 'quantity' => 1]]], $headers)->assertStatus(422);
        $this->assertSame(12.0, $sheet->refresh()->quantity);

        $this->postJson("/api/v1/admin/purchase-orders/{$order->id}/receipts", ['lines' => [['purchase_order_line_id' => $lineId, 'quantity' => 8]]], $headers)
            ->assertStatus(200)->assertJsonPath('data.state', 'received');
        // a received order drops out of the warehouse's list
        $this->assertCount(0, $this->getJson('/api/v1/admin/purchase-orders', $headers)->json('data'));
    }

    public function test_cancel_close_and_evaluate_through_the_api()
    {
        $headers = $this->header('Purchasing');
        $sheet = $this->stocked(0);
        $cancellable = $this->placed([[$sheet, 5, 1]]);
        $partial = $this->placed([[$sheet, 20, 1]]);
        $this->receive($partial, [12]);

        $this->postJson("/api/v1/admin/purchase-orders/{$cancellable->id}/cancel", [], $headers)->assertStatus(200)->assertJsonPath('data.state', 'cancelled');
        $this->postJson("/api/v1/admin/purchase-orders/{$partial->id}/cancel", [], $headers)->assertStatus(422);
        $this->putJson("/api/v1/admin/purchase-orders/{$partial->id}/evaluation", ['score' => 4], $headers)->assertStatus(422);
        $this->postJson("/api/v1/admin/purchase-orders/{$partial->id}/close", [], $headers)->assertStatus(200)->assertJsonPath('data.state', 'closed');

        $this->putJson("/api/v1/admin/purchase-orders/{$partial->id}/evaluation", ['score' => 6], $headers)->assertStatus(422);
        $this->putJson("/api/v1/admin/purchase-orders/{$partial->id}/evaluation", ['score' => 3, 'note' => 'ناقص رسید'], $headers)
            ->assertStatus(200)->assertJsonPath('data.score', 3)->assertJsonPath('data.score_note', 'ناقص رسید');

        $this->getJson("/api/v1/admin/suppliers/{$partial->supplier_id}", $headers)
            ->assertJsonPath('data.score_average', 3)->assertJsonPath('data.score_count', 1);
    }

    public function test_role_boundaries()
    {
        $sheet = $this->stocked(0);
        $draft = $this->draft([[$sheet, 5, 1]]);
        $placed = $this->placed([[$sheet, 5, 1]]);
        $line = $placed->lines()->first()->id;

        $this->postJson("/api/v1/admin/purchase-orders/{$draft->id}/place", [], $this->header('Warehouse'))->assertStatus(403);
        $this->assertSame(PurchaseOrderState::DRAFT, $draft->fresh()->state);
        $this->postJson('/api/v1/admin/purchase-orders', $this->payload(), $this->header('Warehouse'))->assertStatus(403);

        $this->postJson("/api/v1/admin/purchase-orders/{$placed->id}/receipts", ['lines' => [['purchase_order_line_id' => $line, 'quantity' => 1]]], $this->header('Technic'))->assertStatus(403);
        $this->getJson('/api/v1/admin/purchase-orders', $this->header('Technic'))->assertStatus(403);
        $this->assertSame(0.0, $sheet->refresh()->quantity);

        foreach (['Sales', 'Admin'] as $role) {
            $this->getJson('/api/v1/admin/purchase-orders', $this->header($role))->assertStatus(403);
        }
        $this->getJson('/api/v1/admin/purchase-orders', $this->header(User::factory()->create()))->assertStatus(403);
    }

    public function test_super_admin_can_give_the_purchasing_role()
    {
        $staff = User::factory()->create();

        $this->patchJson("/api/v1/admin/staff/{$staff->id}", ['role' => 'Purchasing'], $this->header('SuperAdmin'))->assertStatus(200);

        $this->getJson('/api/v1/admin/suppliers', $this->header($staff->fresh()))->assertStatus(200);

        // the admin learns what they may do from /user
        $permissions = $this->getJson('/api/v1/user', $this->header($staff->fresh()))->assertStatus(200)->json('permissions');
        $this->assertContains('purchasing.manage', $permissions);
        $this->assertContains('costs.view', $permissions);
    }
}
