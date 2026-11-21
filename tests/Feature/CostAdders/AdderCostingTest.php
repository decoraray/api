<?php

namespace Tests\Feature\CostAdders;

use App\Models\WorkOrder;
use App\Models\WorkOrderAdder;

class AdderCostingTest extends AdderTestCase
{
    private function addTo(WorkOrder $job, $adder, float $quantity): void
    {
        WorkOrderAdder::create(['work_order_id' => $job->id, 'cost_adder_id' => $adder->id, 'quantity' => $quantity, 'unit_cost' => $adder->unit_cost]);
    }

    public function test_adders_by_group_in_the_summary()
    {
        $order = $this->costedOrder(10000000);
        $job = $order->workOrders()->first();
        $this->addTo($job, $this->serviceAdder('پرس وکیوم', 'vacuum', 'square_metre', 350000), 3.6);
        $this->addTo($job, $this->serviceAdder('رنگ خاص', 'colour', 'work_order', 500000), 1);

        $s = $this->costing()->summary($order);

        $this->assertSame(1760000, $s['adders_total']);
        $this->assertSame(['rail' => 0, 'magnet' => 0, 'colour' => 500000, 'vacuum' => 1260000, 'misc' => 0], $s['adders_by_group']);
        $this->assertSame(1760000, $s['total_cost']);
        $this->assertSame(1760000, $s['work_orders'][0]['adder_cost']);
        $this->assertSame(['پرس وکیوم', 'رنگ خاص'], array_column($s['work_orders'][0]['adders'], 'name'));

        $this->getJson("/api/v1/admin/orders/{$order->uuid}/costs", $this->header('Accounting'))
            ->assertStatus(200)->assertJsonPath('data.adders_by_group.vacuum', 1260000)
            ->assertJsonFragment(['id' => 'vacuum', 'name' => 'وکیوم']);
    }

    public function test_overhead_includes_adders()
    {
        $this->putJson('/api/v1/admin/costing/settings', ['overhead_percent' => 10], $this->header('Accounting'))->assertStatus(200);
        $sheet = $this->sheet(20, 1000000);
        $order = $this->costedOrder(10000000);
        $job = $order->workOrders()->first();
        $this->use($job, [[$sheet, 4.15, 0, 0]]);
        $this->addTo($job, $this->serviceAdder('رنگ', 'colour', 'piece', 500000), 1);
        $this->postJson("/api/v1/admin/orders/{$order->uuid}/costs", ['category' => 'labor', 'amount' => 1350000], $this->header('Accounting'))->assertStatus(201);

        $s = $this->costing()->summary($order);

        $this->assertSame([4150000, 500000, 1350000, 600000, 6600000], [$s['material_cost'], $s['adders_total'], $s['direct_total'], $s['overhead'], $s['total_cost']]);
    }

    public function test_a_cancelled_work_orders_adders_cost_nothing()
    {
        $order = $this->costedOrder(10000000, [['میز', 5000000, 1], ['کمد', 5000000, 1]]);
        [$live, $cancelled] = $order->workOrders()->orderBy('id')->get()->all();
        $vacuum = $this->serviceAdder('پرس وکیوم');
        $this->addTo($cancelled, $vacuum, 2);
        WorkOrder::whereKey($cancelled->id)->update(['state' => \App\Enums\WorkOrderState::CANCELLED->value]);

        $s = $this->costing()->summary($order);

        $this->assertSame(0, $s['adders_total']);
        $this->assertSame(0, collect($s['work_orders'])->firstWhere('id', $cancelled->id)['adder_cost']);
    }

    public function test_profit_by_product_allocates_adders_to_the_line()
    {
        $product = \App\Models\Product::factory()->create(['title' => 'کابینت']);
        $sheet = $this->sheet(20, 1000000);
        $order = $this->costedOrder(8000000, [['کابینت', 8000000, 1, $product->id]]);
        $job = $order->workOrders()->first();
        $this->use($job, [[$sheet, 3, 3, 0]]);
        $this->addTo($job, $this->serviceAdder('پرس وکیوم'), 2);
        $this->finish($job);
        $this->deliver($order, '2026-11-05 09:00:00');

        $r = $this->getJson('/api/v1/admin/reports/order-profit?from=2026-11-01&to=2026-11-30&group_by=product', $this->header('Accounting'))
            ->assertStatus(200);

        $rows = collect($r->json('rows'))->keyBy('key');
        $this->assertSame([3700000, 4300000], [$rows["p{$product->id}"]['total_cost'], $rows["p{$product->id}"]['profit']]);
        $this->assertArrayNotHasKey('unallocated', $rows->all());
        $this->assertSame($r->json('totals.profit'), (int) $rows->sum('profit'));
    }
}
