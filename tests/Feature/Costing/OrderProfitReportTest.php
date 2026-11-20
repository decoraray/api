<?php

namespace Tests\Feature\Costing;

use App\Models\OrderCost;
use App\Models\Product;

class OrderProfitReportTest extends CostingTestCase
{
    private const URL = '/api/v1/admin/reports/order-profit';

    public function test_period_totals_and_rows()
    {
        $won = $this->costedOrder(10000000);
        OrderCost::create(['order_id' => $won->id, 'category' => 'labor', 'amount' => 6050000]);
        $lost = $this->costedOrder(5000000, [['کمد', 5000000, 1]], ['channel' => 'website']);
        OrderCost::create(['order_id' => $lost->id, 'category' => 'labor', 'amount' => 6050000]);
        $outside = $this->costedOrder(9000000);
        $this->deliver($won, '2026-11-05 09:00:00');
        $this->deliver($lost, '2026-11-10 09:00:00');
        $this->deliver($outside, '2026-10-01 09:00:00');
        $this->costedOrder(7000000);  // not delivered

        $r = $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30', $this->header('Accounting'))->assertStatus(200);

        $r->assertJsonPath('totals.count', 2)
            ->assertJsonPath('totals.revenue', 15000000)
            ->assertJsonPath('totals.profit', 2900000)
            ->assertJsonPath('totals.includes_estimates', true)
            ->assertJsonPath('rows.0.order_id', $lost->id)
            ->assertJsonPath('rows.0.profit', -1050000)
            ->assertJsonPath('rows.1.profit', 3950000)
            ->assertJsonPath('rows.1.margin', 39.5)
            ->assertJsonPath('rows.1.channel', 'instagram');

        // a Jalali month resolves to the same range as the delivered report: آبان ۱۴۰۵ = 2026-10-23 .. 2026-11-21
        $this->getJson(self::URL . '?jalali_year=1405&jalali_month=8', $this->header('Accounting'))
            ->assertStatus(200)->assertJsonPath('totals.count', 2);
        $this->getJson(self::URL, $this->header('Accounting'))->assertStatus(422);
    }

    public function test_by_channel_customer_and_channel_filter()
    {
        $a = $this->costedOrder(10000000);
        $b = $this->costedOrder(4000000, [['کمد', 4000000, 1]]);
        $c = $this->costedOrder(6000000, [['میز', 6000000, 1]], ['channel' => 'website', 'user_id' => $a->user_id]);
        foreach ([$a, $b, $c] as $o) {
            OrderCost::create(['order_id' => $o->id, 'category' => 'labor', 'amount' => 1000000]);
            $this->deliver($o, '2026-11-05 09:00:00');
        }
        $headers = $this->header('Accounting');
        $q = self::URL . '?from=2026-11-01&to=2026-11-30';

        $channels = collect($this->getJson("{$q}&group_by=channel", $headers)->assertStatus(200)->json('rows'))->keyBy('key');
        $this->assertSame([2, 14000000, 2000000, 12000000], [$channels['instagram']['count'], $channels['instagram']['revenue'], $channels['instagram']['total_cost'], $channels['instagram']['profit']]);
        $this->assertSame([1, 5000000], [$channels['website']['count'], $channels['website']['profit']]);

        $customers = collect($this->getJson("{$q}&group_by=customer", $headers)->json('rows'))->keyBy('key');
        $this->assertSame(2, $customers[(string) $a->user_id]['count']);
        $this->assertSame(14000000, $customers[(string) $a->user_id]['profit']);

        $filtered = $this->getJson("{$q}&channel=website", $headers)->json();
        $this->assertSame(1, $filtered['totals']['count']);
        $this->assertSame($c->id, $filtered['rows'][0]['order_id']);
    }

    public function test_by_product_rows_add_up_to_the_total()
    {
        $sheet = $this->sheet(20, 1000000);
        $desk = Product::factory()->create(['title' => 'میز تحریر']);
        $shelf = Product::factory()->create(['title' => 'قفسه']);
        $order = $this->costedOrder(10300000, [['میز', 6000000, 1, $desk->id], ['قفسه', 4000000, 1, $shelf->id], ['سفارشی', 0, 1]]);
        [$deskJob, $shelfJob] = $order->workOrders()->get()->all();
        $this->use($deskJob, [[$sheet, 3, 3, 0]]);
        $this->use($shelfJob, [[$sheet, 2.5, 2.5, 0]]);
        $this->finish($deskJob);
        $this->finish($shelfJob);
        OrderCost::create(['order_id' => $order->id, 'category' => 'transport', 'amount' => 300000]);
        $this->deliver($order, '2026-11-05 09:00:00');

        $r = $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30&group_by=product', $this->header('Accounting'))->assertStatus(200);

        $rows = collect($r->json('rows'))->keyBy('key');
        $this->assertSame(['میز تحریر', 6000000, 3000000, 3000000], [$rows["p{$desk->id}"]['label'], $rows["p{$desk->id}"]['revenue'], $rows["p{$desk->id}"]['total_cost'], $rows["p{$desk->id}"]['profit']]);
        $this->assertSame(1500000, $rows["p{$shelf->id}"]['profit']);
        $this->assertSame('سفارشی', $rows['custom']['label']);
        // shipping in the total (300,000 over the lines) and the transport cost sit in the unallocated row
        $this->assertSame([300000, 300000], [$rows['unallocated']['revenue'], $rows['unallocated']['total_cost']]);
        $this->assertSame($r->json('totals.profit'), (int) $rows->sum('profit'));
        $this->assertSame($r->json('totals.revenue'), (int) $rows->sum('revenue'));
    }

    public function test_finance_view_alone_cannot_see_profit()
    {
        $finance = $this->staff('Sales', ['finance.view']);

        $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30', $this->header($finance))->assertStatus(403);
        $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30', $this->header('SuperAdmin'))->assertStatus(200);
    }

    public function test_previous_period_totals_follow_the_channel_filter()
    {
        $now = $this->costedOrder(10000000);                                            // instagram, Aban
        $before = $this->costedOrder(8000000);                                          // instagram, Mehr
        $site = $this->costedOrder(6000000, [['میز', 6000000, 1]], ['channel' => 'website']); // website, Mehr
        $this->deliver($now, '2026-11-05 09:00:00');
        $this->deliver($before, '2026-10-05 09:00:00');
        $this->deliver($site, '2026-10-06 09:00:00');

        $r = $this->getJson(self::URL . '?jalali_year=1405&jalali_month=8&channel=instagram', $this->header('Accounting'))->assertStatus(200);

        $r->assertJsonPath('totals.count', 1)
            ->assertJsonPath('previous.period', ['from' => '2026-09-23', 'to' => '2026-10-22'])
            ->assertJsonPath('previous.totals.count', 1)
            ->assertJsonPath('previous.totals.revenue', 8000000);
    }

    public function test_csv_by_product_adds_up_and_by_order_lists_every_order()
    {
        $desk = Product::factory()->create(['title' => 'میز تحریر']);
        $order = $this->costedOrder(10300000, [['میز', 6000000, 1, $desk->id], ['سفارشی', 4000000, 1]]);
        OrderCost::create(['order_id' => $order->id, 'category' => 'transport', 'amount' => 300000]);
        $this->deliver($order, '2026-11-05 09:00:00');
        for ($i = 0; $i < 41; $i++) {   // more than one JSON page
            $this->deliver($this->costedOrder(1000000, [['میز', 1000000, 1]]), '2026-11-06 09:00:00');
        }
        $headers = $this->header('Accounting');
        $q = self::URL . '?from=2026-11-01&to=2026-11-30';

        $totals = $this->getJson("{$q}&group_by=product", $headers)->json('totals');
        $res = $this->get("{$q}&group_by=product&format=csv", $headers)->assertStatus(200);
        $this->assertStringContainsString('order-profit-product-2026-11-01-2026-11-30.csv', $res->headers->get('Content-Disposition'));
        $lines = $this->csvLines($res->streamedContent());
        $this->assertSame(['محصول', 'تعداد سفارش', 'تعداد فروش', 'فروش (تومان)', 'بهای تمام‌شده (تومان)', 'سود (تومان)', 'حاشیه (٪)'], $lines[0]);
        $body = array_slice($lines, 1);
        $this->assertContains('تخصیص‌نیافته', array_column($body, 0));
        $this->assertSame($totals['revenue'], (int) array_sum(array_column($body, 3)));
        $this->assertSame($totals['profit'], (int) array_sum(array_column($body, 5)));

        $byOrder = $this->csvLines($this->get("{$q}&format=csv&page=1", $headers)->assertStatus(200)->streamedContent());
        $this->assertCount(1 + 42, $byOrder);
        $this->assertSame('برآوردی', end($byOrder)[8]);
    }

    public function test_finance_view_alone_cannot_export_profit()
    {
        $finance = $this->staff('Sales', ['finance.view']);

        $this->get(self::URL . '?from=2026-11-01&to=2026-11-30&format=csv', $this->header($finance))->assertStatus(403);
    }

    private function csvLines(string $body): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        return array_map('str_getcsv', preg_split('/\r?\n/', trim(substr($body, 3))));
    }
}
