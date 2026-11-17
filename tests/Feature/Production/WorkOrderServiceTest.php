<?php

namespace Tests\Feature\Production;

use App\Enums\OrderStatus;
use App\Enums\ProductionStage;
use App\Enums\WorkOrderState;
use App\Models\OrderNotification;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class WorkOrderServiceTest extends ProductionTestCase
{
    public function test_schema_has_the_work_order_tables_and_one_work_order_per_line()
    {
        $this->assertTrue(Schema::hasColumns('work_orders', [
            'number', 'order_id', 'item_id', 'title', 'quantity', 'dimensions', 'color', 'description',
            'stage', 'state', 'started_at', 'finished_at',
        ]));
        $this->assertTrue(Schema::hasColumns('work_order_stage_logs', [
            'work_order_id', 'stage', 'user_id', 'note', 'entered_at', 'left_at',
        ]));

        $workOrder = WorkOrder::factory()->create();
        $this->expectException(\Illuminate\Database\QueryException::class);
        WorkOrder::factory()->create(['order_id' => $workOrder->order_id, 'item_id' => $workOrder->item_id, 'number' => 'WO-X']);
    }

    public function test_default_grants()
    {
        $names = fn (string $role) => Role::findByName($role)->permissions->pluck('name');

        $this->assertTrue($names('Technic')->contains('production.view'));
        $this->assertTrue($names('Technic')->contains('production.update'));
        $this->assertTrue($names('Sales')->contains('production.view'));
        $this->assertFalse($names('Sales')->contains('production.update'));
        $this->assertFalse($names('Admin')->contains('production.view'));
        $this->assertFalse($names('Admin')->contains('production.update'));
    }

    public function test_stage_maps_to_order_status_with_qc_as_assembly()
    {
        $this->assertSame(OrderStatus::CNC, ProductionStage::CNC->toOrderStatus());
        $this->assertSame(OrderStatus::ASSEMBLY, ProductionStage::QC->toOrderStatus());
        $this->assertSame('کنترل کیفیت', ProductionStage::QC->label());
        $this->assertSame('برش', ProductionStage::CUTTING->label());
        $this->assertSame(OrderStatus::CUTTING, ProductionStage::orderStatuses()[0]);
        $this->assertSame(OrderStatus::PACKAGING, ProductionStage::orderStatuses()[6]);
        $this->assertTrue(ProductionStage::orderIsProducible(OrderStatus::CONFIRMED->value));
        $this->assertFalse(ProductionStage::orderIsProducible(OrderStatus::PAID));
        $this->assertFalse(ProductionStage::orderIsProducible(OrderStatus::SHIPPING));
    }

    public function test_creates_one_waiting_work_order_per_line_with_its_specification()
    {
        $order = $this->order(OrderStatus::CONFIRMED);

        $workOrders = $this->service()->createForOrder($order);

        $this->assertCount(2, $workOrders);
        $first = $workOrders[0];
        $this->assertSame("WO-{$order->id}-1", $first->number);
        $this->assertSame("WO-{$order->id}-2", $workOrders[1]->number);
        $this->assertSame('کمد دیواری', $first->title);
        $this->assertSame(2, (int) $first->quantity);
        $this->assertSame('120x60x75', $first->dimensions);
        $this->assertSame('سفید', $first->color);
        $this->assertSame('با کشو', $first->description);
        $this->assertSame(WorkOrderState::WAITING, $first->state);
        $this->assertNull($first->stage);
    }

    public function test_creating_again_adds_nothing()
    {
        $order = $this->order(OrderStatus::CONFIRMED);

        $this->service()->createForOrder($order);
        $this->service()->createForOrder($order);

        $this->assertSame(2, WorkOrder::count());
    }

    public function test_pending_order_is_rejected()
    {
        $order = $this->order(OrderStatus::PENDING);

        try {
            $this->service()->createForOrder($order);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame(0, WorkOrder::count());
        }
    }

    public function test_move_starts_the_work_order_and_logs_who_and_when()
    {
        $order = $this->order(OrderStatus::CONFIRMED, ['میز']);
        $workOrder = $this->service()->createForOrder($order)[0];
        $a = $this->staff('Technic');
        $b = $this->staff('Technic');

        Carbon::setTestNow('2026-11-17 08:00:00');
        $this->service()->moveToStage($workOrder, ProductionStage::CUTTING, $a);
        Carbon::setTestNow('2026-11-17 10:30:00');
        $workOrder = $this->service()->moveToStage($workOrder, ProductionStage::CNC, $b, 'دو ورق');
        Carbon::setTestNow();

        $this->assertSame(WorkOrderState::IN_PROGRESS, $workOrder->state);
        $this->assertSame(ProductionStage::CNC, $workOrder->stage);
        $this->assertSame('2026-11-17 08:00:00', $workOrder->started_at->toDateTimeString());

        $logs = $workOrder->logs()->get();
        $this->assertCount(2, $logs);
        $this->assertSame(ProductionStage::CUTTING, $logs[0]->stage);
        $this->assertSame($a->id, $logs[0]->user_id);
        $this->assertSame('2026-11-17 08:00:00', $logs[0]->entered_at->toDateTimeString());
        $this->assertSame('2026-11-17 10:30:00', $logs[0]->left_at->toDateTimeString());
        $this->assertSame(ProductionStage::CNC, $logs[1]->stage);
        $this->assertSame($b->id, $logs[1]->user_id);
        $this->assertSame('دو ورق', $logs[1]->note);
        $this->assertNull($logs[1]->left_at);
    }

    public function test_stages_can_be_skipped_and_revisited_and_same_stage_is_not_logged_twice()
    {
        $order = $this->order(OrderStatus::CONFIRMED, ['میز']);
        $workOrder = $this->service()->createForOrder($order)[0];
        $user = $this->staff('Technic');

        $this->service()->moveToStage($workOrder, ProductionStage::CNC, $user);
        $this->service()->moveToStage($workOrder, ProductionStage::CNC, $user);
        $this->service()->moveToStage($workOrder, ProductionStage::QC, $user);
        $workOrder = $this->service()->moveToStage($workOrder, ProductionStage::COLORING, $user, 'خط روی درب');

        $this->assertSame(ProductionStage::COLORING, $workOrder->stage);
        $this->assertSame(['CNC', 'QC', 'COLORING'], $workOrder->logs()->get()->map(fn ($l) => $l->stage->name)->all());
    }

    public function test_complete_closes_the_log_and_a_later_move_reopens()
    {
        $order = $this->order(OrderStatus::CONFIRMED, ['میز']);
        $workOrder = $this->service()->createForOrder($order)[0];
        $user = $this->staff('Technic');

        $this->service()->moveToStage($workOrder, ProductionStage::PACKAGING, $user);
        $done = $this->service()->complete($workOrder);

        $this->assertSame(WorkOrderState::DONE, $done->state);
        $this->assertNotNull($done->finished_at);
        $this->assertNotNull($done->logs()->first()->left_at);

        $reopened = $this->service()->moveToStage($workOrder, ProductionStage::QC, $user);
        $this->assertSame(WorkOrderState::IN_PROGRESS, $reopened->state);
        $this->assertNull($reopened->finished_at);
    }

    public function test_waiting_work_order_cannot_be_completed()
    {
        $order = $this->order(OrderStatus::CONFIRMED, ['میز']);
        $workOrder = $this->service()->createForOrder($order)[0];

        $this->expectException(ValidationException::class);
        $this->service()->complete($workOrder);
    }

    public function test_cancelled_work_order_cannot_be_moved_or_completed()
    {
        $order = $this->order(OrderStatus::CONFIRMED, ['میز']);
        $workOrder = $this->service()->createForOrder($order)[0];
        $this->service()->cancelForOrder($order);

        try {
            $this->service()->moveToStage($workOrder, ProductionStage::CNC, $this->staff('Technic'));
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame(WorkOrderState::CANCELLED, $workOrder->fresh()->state);
        }

        $this->expectException(ValidationException::class);
        $this->service()->complete($workOrder);
    }

    public function test_order_follows_its_least_advanced_started_work_order()
    {
        $order = $this->order(OrderStatus::CONFIRMED);
        [$one, $two] = $this->service()->createForOrder($order)->all();
        $user = $this->staff('Technic');

        // Only one started: the waiting one does not hold the order back.
        $this->service()->moveToStage($one, ProductionStage::ASSEMBLY, $user);
        $this->assertSame(OrderStatus::ASSEMBLY->value, $this->orderStatus($order));

        $this->service()->moveToStage($two, ProductionStage::CNC, $user);
        $this->assertSame(OrderStatus::CNC->value, $this->orderStatus($order));

        // QC shows as assembly on the order.
        $this->service()->moveToStage($two, ProductionStage::QC, $user);
        $this->assertSame(OrderStatus::ASSEMBLY->value, $this->orderStatus($order));

        $this->assertSame(0, OrderNotification::count());
    }

    public function test_first_started_work_order_moves_a_confirmed_order()
    {
        $order = $this->order(OrderStatus::CONFIRMED);
        $one = $this->service()->createForOrder($order)[0];

        $this->service()->moveToStage($one, ProductionStage::CUTTING, $this->staff('Technic'));

        $this->assertSame(OrderStatus::CUTTING->value, $this->orderStatus($order));
    }

    public function test_all_done_leaves_the_order_at_packaging()
    {
        $order = $this->order(OrderStatus::CONFIRMED);
        $user = $this->staff('Technic');

        foreach ($this->service()->createForOrder($order) as $workOrder) {
            $this->service()->moveToStage($workOrder, ProductionStage::ASSEMBLY, $user);
            $this->service()->complete($workOrder);
        }

        $this->assertSame(OrderStatus::PACKAGING->value, $this->orderStatus($order));
    }

    public function test_shipped_order_is_not_touched()
    {
        $order = $this->order(OrderStatus::CONFIRMED);
        $one = $this->service()->createForOrder($order)[0];
        $order->update(['status' => OrderStatus::SHIPPING->value]);

        $this->service()->moveToStage($one, ProductionStage::QC, $this->staff('Technic'));

        $this->assertSame(OrderStatus::SHIPPING->value, $this->orderStatus($order));
    }

    public function test_cancel_for_order_stops_open_work_orders_and_keeps_done_ones()
    {
        $order = $this->order(OrderStatus::CONFIRMED);
        [$one, $two] = $this->service()->createForOrder($order)->all();
        $user = $this->staff('Technic');
        $this->service()->moveToStage($one, ProductionStage::CNC, $user);
        $this->service()->moveToStage($two, ProductionStage::PACKAGING, $user);
        $this->service()->complete($two);

        $this->service()->cancelForOrder($order);

        $this->assertSame(WorkOrderState::CANCELLED, $one->fresh()->state);
        $this->assertNotNull($one->logs()->first()->left_at);
        $this->assertSame(WorkOrderState::DONE, $two->fresh()->state);
    }

    public function test_reinstated_order_gets_its_cancelled_work_orders_back_as_waiting()
    {
        $order = $this->order(OrderStatus::CONFIRMED, ['میز']);
        $workOrder = $this->service()->createForOrder($order)[0];
        $this->service()->moveToStage($workOrder, ProductionStage::CNC, $this->staff('Technic'));
        $this->service()->cancelForOrder($order);

        $order->update(['status' => OrderStatus::CONFIRMED->value]);
        $this->service()->createForOrder($order);

        $workOrder->refresh();
        $this->assertSame(1, WorkOrder::count());
        $this->assertSame(WorkOrderState::WAITING, $workOrder->state);
        $this->assertNull($workOrder->stage);
    }
}
