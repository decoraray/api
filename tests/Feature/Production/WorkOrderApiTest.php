<?php

namespace Tests\Feature\Production;

use App\Enums\OrderStatus;
use App\Enums\ProductionStage;
use App\Enums\WorkOrderState;
use App\Models\Shipping;
use App\Models\Order;
use App\Models\WorkOrder;
use Spatie\Activitylog\Models\Activity;

class WorkOrderApiTest extends ProductionTestCase
{
    // --- created with the order -------------------------------------------------

    public function test_confirming_an_order_creates_its_work_orders_once()
    {
        $order = $this->order(OrderStatus::PAID);
        $headers = $this->header('Sales');

        $this->patchJson("/api/v1/admin/orders/{$order->uuid}", ['status' => 'CONFIRMED'], $headers)->assertStatus(200);
        $this->assertSame(2, WorkOrder::where('order_id', $order->id)->where('state', 'waiting')->count());

        $this->patchJson("/api/v1/admin/orders/{$order->uuid}", ['status' => 'CONFIRMED'], $headers)->assertStatus(200);
        $this->assertSame(2, WorkOrder::count());
    }

    public function test_paid_status_creates_no_work_orders()
    {
        $order = $this->order(OrderStatus::PENDING);

        $this->patchJson("/api/v1/admin/orders/{$order->uuid}", ['status' => 'PAID'], $this->header('Sales'))->assertStatus(200);

        $this->assertSame(0, WorkOrder::count());
    }

    public function test_staff_order_created_confirmed_has_a_work_order()
    {
        $shipping = Shipping::factory()->create();

        $this->postJson('/api/v1/admin/orders', [
            'phone' => '09120000000',
            'customer_name' => 'علی رضایی',
            'lines' => [['title' => 'میز کنسول سفارشی', 'quantity' => 1, 'unit_price' => 5000000, 'dimensions' => '100x40']],
            'address' => 'تهران',
            'shipping_id' => $shipping->id,
            'payment_method' => 'CARD',
            'status' => 'CONFIRMED',
        ], $this->header('Sales'))->assertStatus(201);

        $workOrder = WorkOrder::firstOrFail();
        $this->assertSame('میز کنسول سفارشی', $workOrder->title);
        $this->assertSame('100x40', $workOrder->dimensions);
        $this->assertSame(Order::firstOrFail()->id, $workOrder->order_id);
    }

    public function test_cancelling_the_order_cancels_open_work_orders_and_keeps_done_ones()
    {
        $order = $this->order(OrderStatus::CONFIRMED);
        [$one, $two] = $this->service()->createForOrder($order)->all();
        $user = $this->staff('Technic');
        $this->service()->moveToStage($one, ProductionStage::CNC, $user);
        $this->service()->moveToStage($two, ProductionStage::PACKAGING, $user);
        $this->service()->complete($two);

        $this->patchJson("/api/v1/admin/orders/{$order->uuid}", ['status' => 'CANCELED'], $this->header('Sales'))->assertStatus(200);

        $this->assertSame(WorkOrderState::CANCELLED, $one->fresh()->state);
        $this->assertSame(WorkOrderState::DONE, $two->fresh()->state);
    }

    // --- create for an existing order -------------------------------------------

    public function test_creates_work_orders_for_an_order_already_in_production()
    {
        $order = $this->order(OrderStatus::CNC, ['الف', 'ب', 'ج']);

        $r = $this->postJson("/api/v1/admin/orders/{$order->uuid}/work-orders", [], $this->header('Technic'));

        $r->assertStatus(201)->assertJsonCount(3, 'data');
        $this->assertSame('waiting', $r->json('data.0.state'));
    }

    public function test_pending_order_gets_422_and_no_work_orders()
    {
        $order = $this->order(OrderStatus::PENDING);

        $this->postJson("/api/v1/admin/orders/{$order->uuid}/work-orders", [], $this->header('Technic'))->assertStatus(422);

        $this->assertSame(0, WorkOrder::count());
    }

    // --- list and show ----------------------------------------------------------

    public function test_list_filters_by_stage_state_order_and_search()
    {
        $user = $this->staff('Technic');
        $first = $this->order(OrderStatus::CONFIRMED);
        $second = $this->order(OrderStatus::CONFIRMED, ['کتابخانه']);
        [$a, $b] = $this->service()->createForOrder($first)->all();
        $c = $this->service()->createForOrder($second)[0];
        $this->service()->moveToStage($a, ProductionStage::CNC, $user);
        $this->service()->moveToStage($b, ProductionStage::ASSEMBLY, $user);
        $this->service()->complete($b);
        $headers = $this->header($user);

        $ids = fn (string $query) => collect($this->getJson("/api/v1/admin/work-orders?{$query}", $headers)->assertStatus(200)->json('data'))->pluck('id')->all();

        $this->assertSame([$a->id], $ids('stage=CNC'));
        $this->assertSame([$b->id], $ids('state=done'));
        $this->assertSame([$a->id, $c->id], $ids('state=open'));
        $this->assertSame([$c->id], $ids("order_id={$second->id}"));
        $this->assertSame([$c->id], $ids('search=' . $c->number));
        $this->assertSame([$c->id], $ids('search=' . $second->uuid));
        // open work first, then the rest
        $this->assertSame([$a->id, $c->id, $b->id], $ids(''));

        $r = $this->getJson('/api/v1/admin/work-orders', $headers);
        $this->assertContains(['id' => 'QC', 'name' => 'کنترل کیفیت'], $r->json('stages'));
        $this->assertContains(['id' => 'waiting', 'name' => 'در انتظار'], $r->json('states'));
    }

    public function test_work_order_shows_specification_and_log_but_no_money()
    {
        $order = $this->order(OrderStatus::CONFIRMED, ['کمد']);
        $workOrder = $this->service()->createForOrder($order)[0];
        $user = $this->staff('Technic');
        $this->service()->moveToStage($workOrder, ProductionStage::CUTTING, $user, 'ورق ۱۶');

        $r = $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}", $this->header($user))->assertStatus(200);

        $r->assertJsonPath('data.title', 'کمد')
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.dimensions', '120x60x75')
            ->assertJsonPath('data.color', 'سفید')
            ->assertJsonPath('data.stage', 'CUTTING')
            ->assertJsonPath('data.stage_label', 'برش')
            ->assertJsonPath('data.state', 'in_progress')
            ->assertJsonPath('data.order.uuid', $order->uuid)
            ->assertJsonPath('data.logs.0.stage', 'CUTTING')
            ->assertJsonPath('data.logs.0.note', 'ورق ۱۶')
            ->assertJsonPath('data.logs.0.user.id', $user->id);

        $json = json_encode($r->json());
        foreach (['price', 'total', 'discount', 'payment', 'cost', 'wholesale', 'phone', 'address'] as $key) {
            $this->assertStringNotContainsString('"' . $key, $json, "work order must not expose {$key}");
        }
        $this->assertStringNotContainsString('5000000', $json);
    }

    // --- move and complete ------------------------------------------------------

    public function test_move_and_complete_update_the_work_order_the_order_and_the_audit_log()
    {
        $order = $this->order(OrderStatus::CONFIRMED, ['کمد']);
        $workOrder = $this->service()->createForOrder($order)[0];
        $user = $this->staff('Technic');
        $headers = $this->header($user);

        $r = $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/stage", ['stage' => 'CNC', 'note' => 'فوری'], $headers);

        $r->assertStatus(200)
            ->assertJsonPath('data.stage', 'CNC')
            ->assertJsonPath('data.state', 'in_progress')
            ->assertJsonPath('data.logs.0.note', 'فوری')
            ->assertJsonPath('data.order.status', OrderStatus::CNC->value);
        $this->assertSame(OrderStatus::CNC->value, $this->orderStatus($order));

        $activity = Activity::where('subject_type', WorkOrder::class)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame('CNC', $activity->properties['attributes']['stage']);

        $r = $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/complete", [], $headers);

        $r->assertStatus(200)->assertJsonPath('data.state', 'done');
        $this->assertNotNull($r->json('data.finished_at'));
        $this->assertNotNull($r->json('data.logs.0.left_at'));
        $this->assertSame(OrderStatus::PACKAGING->value, $this->orderStatus($order));
    }

    public function test_invalid_moves_are_rejected_with_422()
    {
        $order = $this->order(OrderStatus::CONFIRMED);
        [$waiting, $cancelled] = $this->service()->createForOrder($order)->all();
        $cancelled->update(['state' => WorkOrderState::CANCELLED]);
        $headers = $this->header('Technic');

        $this->postJson("/api/v1/admin/work-orders/{$waiting->id}/stage", ['stage' => 'SANDING'], $headers)->assertStatus(422);
        $this->postJson("/api/v1/admin/work-orders/{$waiting->id}/stage", [], $headers)->assertStatus(422);
        $this->postJson("/api/v1/admin/work-orders/{$waiting->id}/complete", [], $headers)->assertStatus(422);
        $this->postJson("/api/v1/admin/work-orders/{$cancelled->id}/stage", ['stage' => 'CNC'], $headers)->assertStatus(422);

        $this->assertSame(WorkOrderState::WAITING, $waiting->fresh()->state);
        $this->assertSame(WorkOrderState::CANCELLED, $cancelled->fresh()->state);
    }

    // --- permissions ------------------------------------------------------------

    public function test_sales_can_view_but_not_change()
    {
        $order = $this->order(OrderStatus::CONFIRMED, ['کمد']);
        $workOrder = $this->service()->createForOrder($order)[0];
        $headers = $this->header('Sales');

        $this->getJson('/api/v1/admin/work-orders', $headers)->assertStatus(200);
        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}", $headers)->assertStatus(200);

        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/stage", ['stage' => 'CNC'], $headers)->assertStatus(403);
        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/complete", [], $headers)->assertStatus(403);
        $this->postJson("/api/v1/admin/orders/{$order->uuid}/work-orders", [], $headers)->assertStatus(403);
        $this->assertSame(WorkOrderState::WAITING, $workOrder->fresh()->state);
    }

    public function test_admin_role_and_customers_have_no_access()
    {
        $workOrder = WorkOrder::factory()->create();

        $this->getJson('/api/v1/admin/work-orders', $this->header('Admin'))->assertStatus(403);
        $this->getJson("/api/v1/admin/work-orders/{$workOrder->id}", $this->header('Admin'))->assertStatus(403);
        $this->getJson('/api/v1/admin/work-orders', $this->header(\App\Models\User::factory()->create()))->assertStatus(403);
    }

    public function test_super_admin_passes()
    {
        $workOrder = WorkOrder::factory()->create();

        $this->postJson("/api/v1/admin/work-orders/{$workOrder->id}/stage", ['stage' => 'CUTTING'], $this->header('SuperAdmin'))
            ->assertStatus(200);
    }
}
