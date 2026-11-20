<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Quote;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Reports\DeliveredOrderReport;
use App\Services\Reports\DeliveredOrders;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeliveredOrderReportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/reports/delivered-orders';

    private function as(string $role): array
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($u)];
    }

    /** Quote (2 × 10,000,000 + 500,000 shipping − 1,000,000 discount) with an order. */
    private function invoice(string $status, ?string $deliveredAt, array $payments = [], array $over = []): Quote
    {
        static $n = 0;
        $n++;

        $quote = Quote::create($over + [
            'customer_name' => "مشتری {$n}", 'phone' => '0912000000' . $n, 'status' => 'paid',
            'shipping_mode' => 'amount', 'shipping_amount' => 500000, 'discount' => 1000000, 'deposit_percent' => 60,
        ]);
        $quote->lines()->create(['position' => 0, 'description' => 'میز', 'quantity' => 2, 'unit_price' => 10000000]);

        $order = Order::create([
            'uuid' => "o{$n}", 'user_id' => null, 'address' => 'x', 'city' => 'x', 'county' => 'x', 'postal_code' => '1',
            'phone' => '1', 'status' => $status, 'payment_method' => 'CHEQUE', 'total' => 1, 'quote_id' => $quote->id, 'source' => 'staff',
        ]);
        $quote->order_id = $order->id;
        $quote->save();

        foreach ($payments as $i => [$amount, $method]) {
            DB::table('payments')->insert([
                'uuid' => "p{$n}-{$i}", 'user_phone' => '0912', 'amount' => $amount, 'status' => 'paid', 'method' => $method,
                'order_id' => $order->id, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
            ]);
        }

        if ($deliveredAt) {
            DB::table('activity_log')->insert([
                'log_name' => 'default', 'description' => 'updated', 'event' => 'updated',
                'subject_type' => Order::class, 'subject_id' => $order->id,
                'properties' => json_encode(['attributes' => ['status' => OrderStatus::DELIVERED->value]], JSON_UNESCAPED_UNICODE),
                'created_at' => $deliveredAt, 'updated_at' => $deliveredAt,
            ]);
        }

        return $quote->fresh();
    }

    public function test_range_summary_and_rows(): void
    {
        $a = $this->invoice(OrderStatus::DELIVERED->value, '2026-11-05 09:00:00', [[5000000, 'نقدی'], [4000000, 'چک'], [100, 'نقدی']]);
        $b = $this->invoice(OrderStatus::DELIVERED->value, '2026-11-10 09:00:00', [[19500000, 'کارت به کارت']]);
        $this->invoice(OrderStatus::DELIVERED->value, '2026-10-01 09:00:00', [[1, 'نقدی']]);   // outside range
        $this->invoice(OrderStatus::SHIPPING->value, null, [[1, 'نقدی']]);                      // not delivered

        $r = $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30', $this->as('SuperAdmin'))->assertOk();

        $this->assertSame(2, $r->json('summary.count'));
        $this->assertSame(39000000, $r->json('summary.total_sum'));
        $this->assertSame(9000100 + 19500000, $r->json('summary.paid_sum'));

        $rows = $r->json('rows');
        $this->assertSame($b->number, $rows[0]['quote_number']); // newest first
        $this->assertSame($a->number, $rows[1]['quote_number']);
        $this->assertSame($a->customer_name, $rows[1]['customer_name']);
        $this->assertSame($a->phone, $rows[1]['phone']);
        $this->assertSame(19500000, $rows[1]['total']);
        $this->assertSame(9000100, $rows[1]['paid']);
        $this->assertSame(['نقدی', 'چک'], $rows[1]['methods']);
        $this->assertSame('2026-11-05', $rows[1]['delivered_at']);
    }

    public function test_range_is_inclusive_in_tehran_time(): void
    {
        // 2026-11-30 21:00 UTC = 2026-12-01 00:30 Tehran → belongs to December
        $this->invoice(OrderStatus::DELIVERED->value, '2026-11-30 21:00:00');
        $this->invoice(OrderStatus::DELIVERED->value, '2026-11-30 20:00:00'); // 23:30 Tehran Nov 30

        $nov = $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30', $this->as('SuperAdmin'))->assertOk();
        $this->assertSame(1, $nov->json('summary.count'));
        $dec = $this->getJson(self::URL . '?from=2026-12-01&to=2026-12-01', $this->as('SuperAdmin'))->assertOk();
        $this->assertSame(1, $dec->json('summary.count'));
    }

    public function test_jalali_month_equals_matching_range(): void
    {
        $this->assertSame(['2026-11-22', '2026-12-21'], DeliveredOrderReport::monthRange(1405, 9));
        $this->assertSame(['2026-03-21', '2026-04-20'], DeliveredOrderReport::monthRange(1405, 1));

        $this->invoice(OrderStatus::DELIVERED->value, '2026-11-25 09:00:00');
        $this->invoice(OrderStatus::DELIVERED->value, '2026-11-10 09:00:00');

        $r = $this->getJson(self::URL . '?jalali_year=1405&jalali_month=9', $this->as('SuperAdmin'))->assertOk();
        $this->assertSame(1, $r->json('summary.count'));
        $this->assertSame('2026-11-22', $r->json('period.from'));
    }

    public function test_falls_back_to_order_updated_at_without_log(): void
    {
        $q = $this->invoice(OrderStatus::DELIVERED->value, null);
        DB::table('orders')->where('id', $q->order_id)->update(['updated_at' => '2026-11-07 09:00:00']);

        $r = $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30', $this->as('SuperAdmin'))->assertOk();
        $this->assertSame(1, $r->json('summary.count'));
        $this->assertSame('2026-11-07', $r->json('rows.0.delivered_at'));
    }

    public function test_quote_without_order_is_excluded(): void
    {
        Quote::create(['customer_name' => 'x', 'phone' => '0912', 'status' => 'sent', 'shipping_mode' => 'amount', 'shipping_amount' => 0, 'discount' => 0, 'deposit_percent' => 60]);

        $r = $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30', $this->as('SuperAdmin'))->assertOk();
        $this->assertSame(0, $r->json('summary.count'));
        $this->assertSame([], $r->json('rows'));
    }

    public function test_site_order_without_quote_is_listed_with_items(): void
    {
        $user = User::factory()->create(['name' => 'سارا', 'surname' => 'احمدی']);
        $order = Order::create([
            'uuid' => 'site1', 'user_id' => $user->id, 'address' => 'خیابان ۱', 'city' => 'تهران', 'county' => 'x', 'postal_code' => '1',
            'phone' => '09120000001', 'status' => OrderStatus::DELIVERED->value, 'payment_method' => 'online',
            'total' => 5000000, 'discount_amount' => 500000, 'source' => 'site',
        ]);
        DB::table('items')->insert([
            'title' => 'قفسه', 'slug' => 'shelf', 'order_id' => $order->id, 'quantity' => 2, 'price' => 2500000, 'discount' => 0,
            'color' => 'گردویی', 'dimensions' => '40x80', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payments')->insert([
            'uuid' => 'ps1', 'user_phone' => '0912', 'amount' => 4500000, 'status' => 'paid', 'method' => 'نقدی',
            'order_id' => $order->id, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
        ]);
        DB::table('activity_log')->insert([
            'log_name' => 'default', 'description' => 'updated', 'event' => 'updated',
            'subject_type' => Order::class, 'subject_id' => $order->id,
            'properties' => json_encode(['attributes' => ['status' => OrderStatus::DELIVERED->value]], JSON_UNESCAPED_UNICODE),
            'created_at' => '2026-11-12 09:00:00', 'updated_at' => '2026-11-12 09:00:00',
        ]);

        $r = $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30', $this->as('SuperAdmin'))->assertOk();
        $this->assertSame(1, $r->json('summary.count'));
        $this->assertSame(4500000, $r->json('summary.total_sum'));
        $row = $r->json('rows.0');
        $this->assertSame($order->id, $row['order_id']);
        $this->assertNull($row['quote_id']);
        $this->assertNull($row['quote_number']);
        $this->assertSame('سارا احمدی', $row['customer_name']);
        $this->assertSame('09120000001', $row['phone']);
        $this->assertSame('تهران', $row['city']);
        $this->assertSame(4500000, $row['paid']);
        $this->assertSame(0, $row['remaining']);
        $this->assertSame('قفسه', $row['items'][0]['title']);
        $this->assertSame(2, $row['items'][0]['quantity']);
    }

    public function test_overpayment_does_not_hide_another_orders_debt(): void
    {
        // each invoice is worth 19,500,000
        $over = $this->invoice(OrderStatus::DELIVERED->value, '2026-11-05 09:00:00', [[21500000, 'نقدی']]);
        $under = $this->invoice(OrderStatus::DELIVERED->value, '2026-11-06 09:00:00', [[17500000, 'نقدی']]);

        $r = $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30', $this->as('SuperAdmin'))->assertOk();

        $rows = collect($r->json('rows'))->keyBy('order_id');
        $this->assertSame([0, 2000000], [$rows[$over->order_id]['remaining'], $rows[$over->order_id]['overpaid']]);
        $this->assertSame([2000000, 0], [$rows[$under->order_id]['remaining'], $rows[$under->order_id]['overpaid']]);
        $this->assertSame(Order::find($over->order_id)->uuid, $rows[$over->order_id]['uuid']);
        $this->assertSame(2000000, $r->json('summary.remaining_sum'));
        $this->assertSame(2000000, $r->json('summary.overpaid_sum'));
    }

    public function test_previous_period(): void
    {
        $this->assertSame(['2026-09-23', '2026-10-22'], DeliveredOrders::previous(Request::create('/', 'GET', ['jalali_year' => 1405, 'jalali_month' => 8]), '2026-10-23', '2026-11-21'));
        $this->assertSame(DeliveredOrderReport::monthRange(1404, 12), DeliveredOrders::previous(Request::create('/', 'GET', ['jalali_year' => 1405, 'jalali_month' => 1]), '2026-03-21', '2026-04-20'));
        $this->assertSame(['2026-10-22', '2026-10-31'], DeliveredOrders::previous(Request::create('/'), '2026-11-01', '2026-11-10'));

        $this->invoice(OrderStatus::DELIVERED->value, '2026-11-05 09:00:00');                       // Aban 1405
        $this->invoice(OrderStatus::DELIVERED->value, '2026-10-01 09:00:00', [[1000000, 'نقدی']]); // Mehr 1405
        $this->invoice(OrderStatus::DELIVERED->value, '2026-10-02 09:00:00');                       // Mehr 1405
        $this->invoice(OrderStatus::DELIVERED->value, '2026-08-01 09:00:00');                       // earlier

        $r = $this->getJson(self::URL . '?jalali_year=1405&jalali_month=8', $this->as('SuperAdmin'))->assertOk();
        $this->assertSame(1, $r->json('summary.count'));
        $this->assertSame(['from' => '2026-09-23', 'to' => '2026-10-22'], $r->json('previous.period'));
        $this->assertSame(2, $r->json('previous.summary.count'));
        $this->assertSame(1000000, $r->json('previous.summary.paid_sum'));
    }

    public function test_csv_export(): void
    {
        $a = $this->invoice(OrderStatus::DELIVERED->value, '2026-11-05 09:00:00', [[5000000, 'نقدی'], [4000000, 'چک']]);
        $this->invoice(OrderStatus::DELIVERED->value, '2026-11-10 09:00:00');
        foreach ([['میز', 2, 'گردویی', '120x60'], ['قفسه', 1, null, null]] as [$title, $qty, $color, $dims]) {
            DB::table('items')->insert([
                'title' => $title, 'slug' => $title, 'order_id' => $a->order_id, 'quantity' => $qty, 'price' => 1, 'discount' => 0,
                'color' => $color, 'dimensions' => $dims, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $res = $this->get(self::URL . '?from=2026-11-01&to=2026-11-30&format=csv', $this->as('SuperAdmin'))->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('delivered-orders-2026-11-01-2026-11-30.csv', $res->headers->get('Content-Disposition'));

        $body = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = array_map('str_getcsv', preg_split('/\r?\n/', trim(substr($body, 3))));
        $this->assertSame('شماره سفارش', $lines[0][0]);
        $this->assertCount(3, $lines); // header + 2 orders
        $last = $lines[2]; // older delivery last
        $this->assertSame('میز × 2 (گردویی، 120x60) | قفسه × 1', $last[5]);
        $this->assertSame(['19500000', '9000000', '10500000', '0', 'نقدی، چک', '1405/08/14'], array_slice($last, 6));
    }

    public function test_csv_requires_finance_view(): void
    {
        $this->get(self::URL . '?from=2026-11-01&to=2026-11-30&format=csv', $this->as('Sales'))->assertStatus(403);
        $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30&format=xls', $this->as('SuperAdmin'))->assertStatus(422);
    }

    public function test_validation(): void
    {
        $h = $this->as('SuperAdmin');
        $this->getJson(self::URL, $h)->assertStatus(422);
        $this->getJson(self::URL . '?from=2026-11-10&to=2026-11-01', $h)->assertStatus(422);
        $this->getJson(self::URL . '?from=2026-11-10', $h)->assertStatus(422);
        $this->getJson(self::URL . '?jalali_year=1405&jalali_month=13', $h)->assertStatus(422);
        $this->getJson(self::URL . '?from=2024-01-01&to=2026-01-01', $h)->assertStatus(422);
    }

    public function test_requires_finance_view(): void
    {
        foreach (['Sales', 'Admin', 'Technic'] as $role) {
            $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30', $this->as($role))->assertStatus(403);
        }
        $this->getJson(self::URL . '?from=2026-11-01&to=2026-11-30')->assertStatus(401);
    }

    public function test_cash_received_payment_is_stored_and_labelled(): void
    {
        $h = $this->as('Sales');
        $r = $this->postJson('/api/v1/admin/quotes', [
            'customer_name' => 'علی', 'phone' => '09123456789', 'shipping_mode' => 'on_delivery',
            'lines' => [['description' => 'میز', 'quantity' => 1, 'unit_price' => 1000000]],
        ], $h)->assertStatus(201);
        $id = $r->json('data.id') ?? $r->json('id');

        $p = $this->postJson("/api/v1/admin/quotes/{$id}/payments", ['amount' => 100, 'method' => 'CASH_RECEIVED', 'paid_at' => '2026-11-15'], $h)->assertStatus(201);
        $this->assertSame('CASH_RECEIVED', $p->json('data.payments.0.method'));
        $this->assertSame('نقدی', $p->json('data.payments.0.method_label'));
        $this->assertSame('نقدی', DB::table('payments')->value('method'));
        $this->assertSame('CASH_RECEIVED', DB::table('orders')->value('payment_method'));

        // pay-on-delivery stays distinct
        $c = $this->postJson("/api/v1/admin/quotes/{$id}/payments", ['amount' => 100, 'method' => 'CASH', 'paid_at' => '2026-11-15'], $h)->assertStatus(201);
        $this->assertSame('پرداخت در محل', $c->json('data.payments.1.method_label'));
    }
}
