<?php

namespace Tests\Feature\Costing;

use App\Models\OrderCost;
use App\Services\Inventory\InventoryService;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

class OrderCostServiceTest extends CostingTestCase
{
    public function test_schema_and_grants()
    {
        $this->assertTrue(Schema::hasColumns('order_costs', ['order_id', 'work_order_id', 'category', 'amount', 'note', 'created_by']));

        $names = fn (string $role) => Role::findByName($role)->permissions->pluck('name');
        foreach (['costs.view', 'costs.manage', 'finance.view', 'prices.view', 'orders.view', 'inventory.view', 'purchasing.view'] as $p) {
            $this->assertTrue($names('Accounting')->contains($p), $p);
        }
        foreach (['Sales', 'Technic', 'Warehouse', 'Admin'] as $role) {
            $this->assertFalse($names($role)->contains('costs.view'), $role);
            $this->assertFalse($names($role)->contains('costs.manage'), $role);
        }
        $this->assertTrue($names('Purchasing')->contains('costs.view'));
        $this->assertFalse($names('Purchasing')->contains('costs.manage'));
    }

    // --- material cost ----------------------------------------------------------

    public function test_actual_cost_counts_used_and_wasted_at_the_stored_unit_cost()
    {
        $sheet = $this->sheet();
        $hinge = $this->hinge();
        $order = $this->costedOrder(10000000);
        $job = $order->workOrders()->first();
        $this->use($job, [[$sheet, 3, 2, 0.5], [$hinge, 8, 8, 0]]);
        $this->finish($job);

        $s = $this->costing()->summary($order);

        $this->assertSame(3150000, $s['material_cost']);
        $this->assertSame(550000, $s['waste_cost']);
        $this->assertSame('actual', $s['work_orders'][0]['basis']);
        $this->assertTrue($s['is_final']);

        // a later, dearer delivery does not restate what was already used
        app(InventoryService::class)->receive($sheet, 10, 2000000, null);
        $this->assertSame(3150000, $this->costing()->summary($order)['material_cost']);
    }

    public function test_estimate_before_production_uses_todays_average()
    {
        $sheet = $this->sheet();
        $order = $this->costedOrder(10000000);
        $this->use($order->workOrders()->first(), [[$sheet, 3, 0, 0]]);

        $s = $this->costing()->summary($order);

        $this->assertSame(3300000, $s['material_cost']);
        $this->assertSame('estimate', $s['work_orders'][0]['basis']);
        $this->assertFalse($s['is_final']);
    }

    public function test_one_job_done_and_one_waiting_is_still_an_estimate()
    {
        $sheet = $this->sheet();
        $hinge = $this->hinge();
        $order = $this->costedOrder(20000000, [['میز', 5000000, 1], ['کمد', 5000000, 1]]);
        [$done, $waiting] = $order->workOrders()->get()->all();
        $this->use($done, [[$sheet, 3, 2, 0.5], [$hinge, 8, 8, 0]]);
        $this->finish($done);
        $this->use($waiting, [[$sheet, 3, 0, 0]]);

        $s = $this->costing()->summary($order);

        $this->assertSame(6450000, $s['material_cost']);
        $this->assertFalse($s['is_final']);
    }

    public function test_all_done_is_final_and_cancelled_jobs_count_only_what_they_used()
    {
        $sheet = $this->sheet();
        $order = $this->costedOrder(20000000, [['میز', 5000000, 1], ['کمد', 5000000, 1]]);
        [$a, $b] = $order->workOrders()->get()->all();
        $this->use($a, [[$sheet, 3, 1, 0]]);
        $this->finish($a);
        $this->use($b, [[$sheet, 5, 1, 0]]);
        $b->update(['state' => \App\Enums\WorkOrderState::CANCELLED]);

        $s = $this->costing()->summary($order);

        // 1 sheet each, not b's 5 planned
        $this->assertSame(2200000, $s['material_cost']);
        $this->assertTrue($s['is_final']);
    }

    public function test_no_material_recorded()
    {
        $order = $this->costedOrder(10000000);

        $s = $this->costing()->summary($order);

        $this->assertSame(0, $s['material_cost']);
        $this->assertFalse($s['has_materials']);
    }

    // --- direct costs, overhead, profit -----------------------------------------

    public function test_overhead_on_material_and_direct_costs()
    {
        app(Settings::class)->set('costing.overhead_percent', 10);
        $sheet = $this->sheet(20, 1000000);
        $order = $this->costedOrder(10000000, [['میز', 5000000, 1], ['کمد', 5000000, 1]]);
        [$a, $b] = $order->workOrders()->get()->all();
        $this->use($a, [[$sheet, 3, 3, 0]]);
        $this->use($b, [[$sheet, 1.15, 1.15, 0]]);
        $this->finish($a);
        $this->finish($b);
        OrderCost::create(['order_id' => $order->id, 'work_order_id' => $a->id, 'category' => 'labor', 'amount' => 800000]);
        OrderCost::create(['order_id' => $order->id, 'category' => 'packaging', 'amount' => 250000]);
        OrderCost::create(['order_id' => $order->id, 'category' => 'transport', 'amount' => 300000]);

        $s = $this->costing()->summary($order);

        $this->assertSame(4150000, $s['material_cost']);
        $this->assertSame(['labor' => 800000, 'packaging' => 250000, 'transport' => 300000, 'other' => 0], $s['direct_costs']);
        $this->assertSame(1350000, $s['direct_total']);
        $this->assertSame(550000, $s['overhead']);
        $this->assertSame(6050000, $s['total_cost']);
        $this->assertSame(10000000, $s['revenue']);
        $this->assertSame(3950000, $s['profit']);
        $this->assertSame(39.5, $s['margin']);
        $this->assertSame(800000, $s['work_orders'][0]['direct_cost']);
    }

    public function test_overhead_defaults_to_zero_and_a_loss_is_negative()
    {
        $order = $this->costedOrder(5000000);
        OrderCost::create(['order_id' => $order->id, 'category' => 'other', 'amount' => 6050000]);

        $s = $this->costing()->summary($order);

        $this->assertSame(0.0, $s['overhead_percent']);
        $this->assertSame(0, $s['overhead']);
        $this->assertSame(-1050000, $s['profit']);
    }

    public function test_revenue_is_total_with_shipping_minus_discount_even_for_a_part_paid_order()
    {
        $order = $this->costedOrder(10100000, [['میز', 10000000, 1]], ['discount_amount' => 100000, 'payment_method' => 'partial']);
        $zero = $this->costedOrder(0, [['هدیه', 0, 1]]);

        $this->assertSame(10000000, $this->costing()->summary($order)['revenue']);
        $this->assertNull($this->costing()->summary($zero)['margin']);
    }

    public function test_summaries_match_summary_with_a_fixed_number_of_queries()
    {
        $sheet = $this->sheet(100);
        $orders = collect();
        foreach (range(1, 4) as $i) {
            $order = $this->costedOrder(10000000 * $i, [['میز', 1, 1], ['کمد', 1, 1]]);
            foreach ($order->workOrders()->get() as $job) {
                $this->use($job, [[$sheet, 2, $i % 2, 0]]);
            }
            OrderCost::create(['order_id' => $order->id, 'category' => 'labor', 'amount' => 100000 * $i]);
            $orders->push($order);
        }

        $count = function ($orders) {
            $orders = $orders->map->fresh();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $this->costing()->summaries($orders);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$result, $n];
        };

        // settings are cached after the first read; warm it so both runs compare like with like
        $this->costing()->overheadPercent();
        [$all, $many] = $count($orders);
        [, $one] = $count($orders->take(1));

        foreach ($orders as $order) {
            $this->assertEquals($this->costing()->summary($order->fresh()), $all[$order->id]);
        }
        $this->assertSame($one, $many);
    }
}
