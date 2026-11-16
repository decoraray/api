<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Quote;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Reports\DeliveredInvoiceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeliveredInvoiceReportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/reports/delivered-invoices';

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
        $this->assertSame(39000000, $r->json('summary.invoice_total_sum'));
        $this->assertSame(9000100 + 19500000, $r->json('summary.paid_sum'));

        $rows = $r->json('rows');
        $this->assertSame($b->number, $rows[0]['number']); // newest first
        $this->assertSame($a->number, $rows[1]['number']);
        $this->assertSame($a->customer_name, $rows[1]['customer_name']);
        $this->assertSame($a->phone, $rows[1]['phone']);
        $this->assertSame(19500000, $rows[1]['invoice_total']);
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
        $this->assertSame(['2026-11-22', '2026-12-21'], DeliveredInvoiceReport::monthRange(1405, 9));
        $this->assertSame(['2026-03-21', '2026-04-20'], DeliveredInvoiceReport::monthRange(1405, 1));

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
