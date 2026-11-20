<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Concerns\RecordsQuotePayments;
use Tests\TestCase;

class PaymentVoidTest extends TestCase
{
    use RefreshDatabase;
    use RecordsQuotePayments;

    private function voidPayment(Payment $p, array $headers, ?string $reason = 'ثبت روی پیش‌فاکتور اشتباه')
    {
        return $this->postJson("/api/v1/admin/payments/{$p->id}/void", $reason === null ? [] : ['reason' => $reason], $headers);
    }

    public function test_superadmin_voids_payment_which_is_kept_with_reason_user_and_time()
    {
        $sales = $this->staffUser();
        $admin = $this->staffUser('SuperAdmin');
        $q = $this->quoteFor($sales);
        $this->payQuote($q, $sales, 3000000);
        $p = $this->payQuote($q, $sales, 2000000);

        $this->voidPayment($p, $this->headersFor($admin))
            ->assertOk()
            ->assertJsonPath('data.paid', 3000000)
            ->assertJsonPath('data.remaining', 7000000)
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.1.status', 'void')
            ->assertJsonPath('data.payments.1.void_reason', 'ثبت روی پیش‌فاکتور اشتباه')
            ->assertJsonPath('data.payments.1.voided_by', $admin->name)
            ->assertJsonPath('data.payments.1.can_edit', false)
            ->assertJsonPath('data.payments.1.can_void', false);

        $p->refresh();
        $this->assertSame('void', $p->status);
        $this->assertSame($admin->id, $p->voided_by);
        $this->assertNotNull($p->voided_at);
    }

    public function test_reason_is_required()
    {
        $sales = $this->staffUser();
        $p = $this->payQuote($this->quoteFor($sales), $sales, 2000000);

        $this->voidPayment($p, $this->headersFor($this->staffUser('SuperAdmin')), null)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('paid', $p->fresh()->status);
    }

    public function test_sales_cannot_void()
    {
        $sales = $this->staffUser();
        $p = $this->payQuote($this->quoteFor($sales), $sales, 2000000);

        $this->voidPayment($p, $this->headersFor($sales))->assertStatus(403);
        $this->assertSame('paid', $p->fresh()->status);
    }

    public function test_gateway_and_already_voided_payments_are_refused()
    {
        $sales = $this->staffUser();
        $h = $this->headersFor($this->staffUser('SuperAdmin'));
        $p = $this->payQuote($this->quoteFor($sales), $sales, 2000000);

        $p->forceFill(['gateway' => 'payping'])->save();
        $this->voidPayment($p, $h)->assertStatus(422);
        $this->assertSame('paid', $p->fresh()->status);

        $p->forceFill(['gateway' => null])->save();
        $this->voidPayment($p, $h)->assertOk();
        $this->voidPayment($p, $h, 'again')->assertStatus(422);
        $this->assertSame('ثبت روی پیش‌فاکتور اشتباه', $p->fresh()->void_reason);
    }

    public function test_void_is_audited_with_reason()
    {
        $sales = $this->staffUser();
        $admin = $this->staffUser('SuperAdmin');
        $p = $this->payQuote($this->quoteFor($sales), $sales, 2000000);

        $this->voidPayment($p, $this->headersFor($admin))->assertOk();

        $entry = Activity::where('subject_type', Payment::class)->where('subject_id', $p->id)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $entry->causer_id);
        $this->assertSame('paid', $entry->properties['old']['status']);
        $this->assertSame('void', $entry->properties['attributes']['status']);
        $this->assertSame('ثبت روی پیش‌فاکتور اشتباه', $entry->properties['attributes']['void_reason']);
    }

    // ---- quote status -------------------------------------------------------

    public function test_voiding_only_payment_returns_quote_to_sent_but_keeps_it_locked()
    {
        $sales = $this->staffUser();
        $h = $this->headersFor($sales);
        $q = $this->quoteFor($sales);
        $p = $this->payQuote($q, $sales, 3000000);
        $this->assertSame('deposit_paid', $q->fresh()->status);

        $this->voidPayment($p, $this->headersFor($this->staffUser('SuperAdmin')))->assertOk();
        $this->assertSame('sent', $q->fresh()->status);

        $this->putJson("/api/v1/admin/quotes/{$q->id}", [
            'customer_name' => 'علی رضایی', 'phone' => '09123456789', 'shipping_mode' => 'on_delivery',
            'lines' => [['description' => 'میز', 'quantity' => 2, 'unit_price' => 10000000]],
        ], $h)->assertStatus(422);
        $this->postJson("/api/v1/admin/quotes/{$q->id}/cancel", [], $h)->assertStatus(422);
        $this->assertSame('sent', $q->fresh()->status);
    }

    public function test_voiding_part_of_full_payment_returns_quote_to_deposit_paid()
    {
        $sales = $this->staffUser();
        $q = $this->quoteFor($sales);
        $this->payQuote($q, $sales, 6000000);
        $p = $this->payQuote($q, $sales, 4000000);
        $this->assertSame('paid', $q->fresh()->status);

        $this->voidPayment($p, $this->headersFor($this->staffUser('SuperAdmin')))->assertOk();

        $this->assertSame('deposit_paid', $q->fresh()->status);
        $this->getJson("/api/v1/admin/quotes/{$q->id}", $this->headersFor($sales))
            ->assertJsonPath('data.totals.remaining', 4000000);
    }

    public function test_pay_again_after_voiding_wrong_amount()
    {
        $sales = $this->staffUser();
        $q = $this->quoteFor($sales);
        $wrong = $this->payQuote($q, $sales, 10000000);
        $this->assertSame('paid', $q->fresh()->status);

        $this->voidPayment($wrong, $this->headersFor($this->staffUser('SuperAdmin')))->assertOk();
        $this->payQuote($q, $sales, 1000000);

        $q->refresh();
        $this->assertSame('deposit_paid', $q->status);
        $this->assertSame(1, Order::count(), 'the existing order is reused');
    }

    // ---- totals -------------------------------------------------------------

    public function test_voided_payment_is_excluded_from_every_total()
    {
        $sales = $this->staffUser();
        $admin = $this->staffUser('SuperAdmin');
        $q = $this->quoteFor($sales);
        $kept = $this->payQuote($q, $sales, 3000000);
        $p = $this->payQuote($q, $sales, 2000000);
        $order = $p->order;
        // Both paid "today" so they fall in the dashboard's today and month revenue.
        DB::table('payments')->whereIn('id', [$kept->id, $p->id])->update(['created_at' => now(), 'updated_at' => now()]);

        $this->voidPayment($p, $this->headersFor($admin))->assertOk();
        $h = $this->headersFor($admin);

        $this->getJson("/api/v1/admin/orders/uuid/{$order->uuid}", $h)
            ->assertJsonPath('data.paid', 3000000)->assertJsonPath('data.remaining', 7000000);
        $this->getJson("/api/v1/admin/quotes/{$q->id}", $h)->assertJsonPath('data.totals.paid', 3000000);

        $crm = $this->getJson("/api/v1/admin/users/{$order->user_id}/crm", $h)->assertOk();
        $this->assertSame(3000000, collect($crm->json('orders'))->firstWhere('id', $order->id)['paid']);

        $stats = $this->getJson('/api/v1/admin/dashboard/statistics', $h)->assertOk();
        $this->assertSame(3000000, $stats->json('revenue.total'));
        $this->assertSame(3000000, $stats->json('revenue.today'));

        // Delivered-orders report
        $order->forceFill(['status' => OrderStatus::DELIVERED->value])->save();
        DB::table('activity_log')->insert([
            'log_name' => 'default', 'description' => 'updated', 'event' => 'updated',
            'subject_type' => Order::class, 'subject_id' => $order->id,
            'properties' => json_encode(['attributes' => ['status' => OrderStatus::DELIVERED->value]], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $day = now('Asia/Tehran')->format('Y-m-d');
        $this->getJson("/api/v1/admin/reports/delivered-orders?from={$day}&to={$day}", $h)
            ->assertOk()->assertJsonPath('summary.paid_sum', 3000000);
    }

    // ---- permission ---------------------------------------------------------

    public function test_permission_exists_and_no_role_has_it()
    {
        $this->assertTrue(Permission::where('name', 'payments.void')->exists());
        foreach (['Sales', 'Technic', 'Admin', 'Accounting'] as $role) {
            $u = $this->staffUser($role);
            $this->assertFalse($u->can('payments.void'), "{$role} must not void payments");
        }
        $this->assertTrue($this->staffUser('SuperAdmin')->can('payments.void'));
    }
}
