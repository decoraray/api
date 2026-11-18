<?php

namespace Tests\Feature\Costing;

use App\Enums\OrderStatus;
use App\Enums\ProductionStage;
use App\Models\Item;
use App\Models\Order;
use App\Models\StockItem;
use App\Models\WorkOrder;
use App\Services\Costing\OrderCostService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Production\MaterialTestCase;

abstract class CostingTestCase extends MaterialTestCase
{
    protected function costing(): OrderCostService
    {
        return app(OrderCostService::class);
    }

    /** A confirmed order worth `$revenue` (total incl. shipping minus discount) with one line per [title, price, qty, ?product id]. */
    protected function costedOrder(int $revenue, array $lines = [['میز', 5000000, 1]], array $attributes = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'status' => OrderStatus::CONFIRMED->value,
            'total' => $revenue,
            'discount_amount' => 0,
            'channel' => 'instagram',
        ], $attributes));

        foreach ($lines as $line) {
            Item::create(['title' => $line[0], 'slug' => 'x', 'price' => $line[1], 'quantity' => $line[2], 'product_id' => $line[3] ?? null, 'order_id' => $order->id]);
        }
        $this->service()->createForOrder($order);

        return $order->refresh();
    }

    /** Plans, uses and wastes material on a work order: [[StockItem, planned, used, wasted], ...]. */
    protected function use(WorkOrder $workOrder, array $lines): void
    {
        $this->materials()->replacePlan($workOrder, array_map(fn ($l) => ['stock_item_id' => $l[0]->id, 'quantity' => $l[1]], $lines));
        foreach ($workOrder->materials()->get() as $i => $material) {
            [, , $used, $wasted] = $lines[$i];
            if ($used + $wasted > 0) {
                $this->materials()->consume($material, $used, $wasted, null);
            }
        }
    }

    protected function finish(WorkOrder $workOrder): void
    {
        $this->service()->moveToStage($workOrder, ProductionStage::PACKAGING, null);
        $this->service()->complete($workOrder);
    }

    /** Marks the order delivered on a given moment (UTC), the way the reports read it. */
    protected function deliver(Order $order, string $at): void
    {
        Order::whereKey($order->id)->update(['status' => OrderStatus::DELIVERED->value]);
        DB::table('activity_log')->insert([
            'log_name' => 'default', 'description' => 'updated', 'event' => 'updated',
            'subject_type' => Order::class, 'subject_id' => $order->id,
            'properties' => json_encode(['attributes' => ['status' => OrderStatus::DELIVERED->value]], JSON_UNESCAPED_UNICODE),
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    protected function sheet(float $quantity = 20, int $cost = 1100000): StockItem
    {
        return $this->stock($quantity, ['kind' => 'mdf_sheet', 'unit' => 'sheet', 'thickness_mm' => 16, 'name' => 'ورق'], $cost);
    }

    protected function hinge(float $quantity = 50, int $cost = 50000): StockItem
    {
        return $this->stock($quantity, ['name' => 'لولا'], $cost);
    }
}
