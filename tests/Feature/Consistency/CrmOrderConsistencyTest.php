<?php

namespace Tests\Feature\Consistency;

use App\Enums\LeadStage;
use App\Models\Address;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Shipping;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use App\Services\Leads\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CrmOrderConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . AuthService::createToken($u)];
    }

    private function lead(array $over = []): Lead
    {
        return app(LeadService::class)->create($over + ['name' => 'سارا', 'phone' => '09121234567', 'source' => 'instagram'], $this->user('Sales'));
    }

    private function quoteFor(Lead $lead, User $sales, array $over = []): Quote
    {
        $r = $this->postJson('/api/v1/admin/quotes', $over + [
            'lead_id' => $lead->id,
            'customer_name' => 'سارا',
            'phone' => $lead->phone,
            'shipping_mode' => 'on_delivery',
            'lines' => [['description' => 'میز', 'quantity' => 1, 'unit_price' => 10000000]],
        ], $this->h($sales));
        $r->assertStatus(201);

        return Quote::findOrFail($r->json('data.id') ?? $r->json('id'));
    }

    private function pay(Quote $q, User $sales, int $amount)
    {
        return $this->postJson("/api/v1/admin/quotes/{$q->id}/payments", [
            'amount' => $amount, 'method' => 'CASH_RECEIVED', 'paid_at' => '2026-11-10',
        ], $this->h($sales));
    }

    // ---- quote status ------------------------------------------------------

    public function test_deposit_makes_deposit_paid_and_full_payment_makes_paid()
    {
        $sales = $this->user('Sales');
        $q = $this->quoteFor($this->lead(), $sales);

        $this->pay($q, $sales, 6000000)->assertStatus(201)->assertJsonPath('data.status', 'deposit_paid');

        // locked and not cancellable while only the deposit is paid
        $this->putJson("/api/v1/admin/quotes/{$q->id}", ['customer_name' => 'x', 'phone' => '09121234567', 'shipping_mode' => 'on_delivery',
            'lines' => [['description' => 'a', 'quantity' => 1, 'unit_price' => 1]]], $this->h($sales))->assertStatus(422);
        $this->postJson("/api/v1/admin/quotes/{$q->id}/cancel", [], $this->h($sales))->assertStatus(422);

        $this->pay($q, $sales, 4000000)->assertStatus(201)->assertJsonPath('data.status', 'paid');
        $this->getJson('/api/v1/admin/quotes?status=deposit_paid', $this->h($sales))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_order_from_quote_carries_lead_channel_and_link()
    {
        $sales = $this->user('Sales');
        $lead = $this->lead(['source' => 'divar']);
        $lead->update(['attribution' => ['utm' => 'a']]);
        $q = $this->quoteFor($lead, $sales);
        $this->pay($q, $sales, 6000000)->assertStatus(201);

        $order = Order::find($q->fresh()->order_id);
        $this->assertSame('divar', $order->channel);
        $this->assertSame(['utm' => 'a'], $order->attribution);
    }

    public function test_quote_without_lead_leaves_channel_empty_but_closes_open_lead_by_phone()
    {
        $sales = $this->user('Sales');
        $lead = $this->lead();
        $r = $this->postJson('/api/v1/admin/quotes', [
            'customer_name' => 'سارا', 'phone' => '09121234567', 'shipping_mode' => 'on_delivery',
            'lines' => [['description' => 'میز', 'quantity' => 1, 'unit_price' => 10000000]],
        ], $this->h($sales))->assertStatus(201);
        $q = Quote::findOrFail($r->json('data.id'));
        $this->pay($q, $sales, 6000000)->assertStatus(201);

        $this->assertNull(Order::find($q->fresh()->order_id)->channel);
        $this->assertSame('won', $lead->fresh()->stage);
    }

    // ---- lead outcome ------------------------------------------------------

    public function test_paid_quote_reopens_a_lost_lead()
    {
        $sales = $this->user('Sales');
        $lead = $this->lead();
        $q = $this->quoteFor($lead, $sales);
        app(LeadService::class)->changeStage($lead, LeadStage::LOST, 'گران', $sales);

        $this->pay($q, $sales, 6000000)->assertStatus(201);

        $lead->refresh();
        $this->assertSame('won', $lead->stage);
        $this->assertNull($lead->lost_reason);
        $this->assertTrue($lead->activities()->where('type', 'stage_changed')->get()
            ->contains(fn ($a) => ($a->data['reopened'] ?? false) === true));
    }

    public function test_site_order_payment_closes_open_lead_by_phone_and_reopens_lost()
    {
        $customer = User::factory()->create(['phone' => '09121234567']);
        $open = $this->lead();
        $this->assertSame($customer->id, $open->user_id);

        $order = Order::factory()->create(['user_id' => $customer->id, 'phone' => '09121234567', 'source' => 'site']);
        $payment = Payment::create(['uuid' => uniqid(), 'order_id' => $order->id, 'user_id' => $customer->id, 'user_phone' => '09121234567', 'status' => 'paid', 'method' => 'online', 'amount' => 5000]);

        $lead = app(LeadService::class)->orderPaid($order->fresh('user'), $payment);
        $this->assertSame('won', $lead->stage);
        $this->assertSame($order->id, $lead->activities()->where('type', 'payment')->first()->data['order_id']);

        // a lost lead is reopened by the next paid order of that customer
        $lost = $this->lead(['phone' => '09127654321']);
        $c2 = User::factory()->create(['phone' => '09127654321']);
        app(LeadService::class)->changeStage($lost, LeadStage::LOST, 'x', null);
        $o2 = Order::factory()->create(['user_id' => $c2->id, 'phone' => '09127654321', 'source' => 'site']);
        app(LeadService::class)->orderPaid($o2->fresh('user'), null, 'order_placed');
        $this->assertSame('won', $lost->fresh()->stage);
    }

    public function test_site_order_without_lead_creates_nothing()
    {
        $customer = User::factory()->create(['phone' => '09125550000']);
        $order = Order::factory()->create(['user_id' => $customer->id, 'phone' => '09125550000']);

        $this->assertNull(app(LeadService::class)->orderPaid($order->fresh('user'), null));
        $this->assertSame(0, Lead::count());
    }

    public function test_cash_checkout_closes_the_open_lead()
    {
        Shipping::factory()->create(['id' => 1]);
        $customer = User::factory()->create(['phone' => '09121234567']);
        $lead = $this->lead();
        $address = Address::factory()->create(['user_id' => $customer->id]);
        $variation = Variation::factory()->create(['price' => 100000]);
        $this->postJson('/api/v1/add', ['variation' => $variation->id], $this->h($customer))->assertStatus(200);

        $this->postJson('/api/v1/checkout', ['address_id' => $address->uuid, 'payment_method' => 'cash'], $this->h($customer))->assertStatus(201);

        $this->assertSame('won', $lead->fresh()->stage);
        $this->assertTrue($lead->activities()->where('type', 'order_placed')->exists());
    }

    // ---- customer link ------------------------------------------------------

    public function test_registration_links_existing_leads_by_phone_in_both_spellings()
    {
        $a = $this->lead(['phone' => '09121234567']);
        $b = $this->lead(['phone' => '09127777777']);
        $this->assertNull($a->user_id);

        $u = User::factory()->create(['phone' => '9121234567']);
        $this->assertSame($u->id, $a->fresh()->user_id);
        $this->assertNull($b->fresh()->user_id);
    }

    public function test_changing_a_users_phone_links_leads()
    {
        $lead = $this->lead(['phone' => '09128888888']);
        $u = User::factory()->create(['phone' => '09120000001']);
        $u->update(['phone' => '09128888888']);
        $this->assertSame($u->id, $lead->fresh()->user_id);
    }

    // ---- cancelled orders ---------------------------------------------------

    public function test_cancelled_order_is_excluded_from_revenue_refuses_payments_and_notes_the_lead()
    {
        $sales = $this->user('Sales');
        $owner = $this->user('SuperAdmin');
        $lead = $this->lead();
        $q = $this->quoteFor($lead, $sales);
        $this->pay($q, $sales, 6000000)->assertStatus(201);
        $order = Order::find($q->fresh()->order_id);

        $today = Carbon::now('Asia/Tehran')->format('Y-m-d');
        $report = fn () => $this->getJson("/api/v1/admin/dashboard/report?from=$today&to=$today", $this->h($owner))->assertOk()->json('money.received');
        Payment::where('order_id', $order->id)->update(['updated_at' => now()]);
        $this->assertSame(6000000, $report());

        $this->patchJson("/api/v1/admin/orders/{$order->uuid}", ['status' => 'CANCELED'], $this->h($owner))->assertOk();

        $this->assertSame(0, $report());
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->pay($q, $sales, 1000000)->assertStatus(422);
        $this->assertSame('won', $lead->fresh()->stage);
        $this->assertSame('deposit_paid', $q->fresh()->status);
        $this->assertTrue($lead->activities()->where('type', 'order_cancelled')->exists());
        $this->getJson("/api/v1/admin/quotes/{$q->id}", $this->h($sales))->assertJsonPath('data.order_status', 'لغو شده');
    }

    // ---- order balance and customer view ------------------------------------

    public function test_order_page_shows_paid_and_remaining_only_with_price_access()
    {
        $sales = $this->user('Sales');
        $q = $this->quoteFor($this->lead(), $sales);
        $this->pay($q, $sales, 6000000)->assertStatus(201);
        $order = Order::find($q->fresh()->order_id);

        $r = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->h($sales))->assertOk();
        $this->assertSame(6000000, $r->json('data.paid'));
        $this->assertSame(4000000, $r->json('data.remaining'));
        $this->assertSame($q->id, $r->json('data.quote_id'));

        $r = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->h($this->user('Technic')))->assertOk();
        $this->assertArrayNotHasKey('paid', $r->json('data'));
        $this->assertArrayNotHasKey('remaining', $r->json('data'));
    }

    public function test_customer_view_lists_history_and_gates_sections_and_money()
    {
        $sales = $this->user('Sales');
        $lead = $this->lead();
        $q = $this->quoteFor($lead, $sales);
        $this->pay($q, $sales, 6000000)->assertStatus(201);
        $customer = User::where('phone', '09121234567')->first();

        $r = $this->getJson("/api/v1/admin/users/{$customer->id}/crm", $this->h($sales))->assertOk()->json();
        $this->assertSame('consumer', $r['customer_type']);
        $this->assertCount(1, $r['leads']);
        $this->assertCount(1, $r['quotes']);
        $this->assertCount(1, $r['orders']);
        $this->assertSame(6000000, $r['total_paid']);
        $this->assertSame(4000000, $r['quotes'][0]['remaining']);

        // a viewer with customers.view and orders.view but neither quotes nor leads
        $admin = User::factory()->create();
        $admin->givePermissionTo('customers.view', 'orders.view');
        $r = $this->getJson("/api/v1/admin/users/{$customer->id}/crm", $this->h($admin))->assertOk()->json();
        $this->assertArrayNotHasKey('leads', $r);
        $this->assertArrayNotHasKey('quotes', $r);
        $this->assertArrayHasKey('orders', $r);

        $this->getJson("/api/v1/admin/users/{$customer->id}/crm", $this->h($this->user('Technic')))->assertStatus(403);
    }

    public function test_customer_type_is_wholesale_when_a_quote_uses_the_wholesale_tier()
    {
        $sales = $this->user('Sales');
        $lead = $this->lead();
        $q = $this->quoteFor($lead, $sales, ['price_tier' => 'wholesale']);
        $customer = User::where('phone', '09121234567')->first();

        $r = $this->getJson("/api/v1/admin/users/{$customer->id}/crm", $this->h($sales))->assertOk()->json();
        $this->assertSame('wholesale', $r['customer_type']);
        $this->assertSame('عمده', $r['customer_type_label']);
    }

    // ---- migration ------------------------------------------------------------

    public function test_backfill_migration_fixes_old_rows()
    {
        $sales = $this->user('Sales');
        $lead = $this->lead(['source' => 'divar']);
        $q = $this->quoteFor($lead, $sales);
        $this->pay($q, $sales, 6000000)->assertStatus(201);

        // simulate the pre-change data
        $q->refresh();
        \DB::table('quotes')->where('id', $q->id)->update(['status' => 'paid']);
        \DB::table('orders')->where('id', $q->order_id)->update(['channel' => null]);
        \DB::table('leads')->where('id', $lead->id)->update(['user_id' => null]);

        $migration = require base_path('database/migrations/2026_11_23_000000_backfill_crm_order_consistency.php');
        $migration->up();

        $this->assertSame('deposit_paid', $q->fresh()->status);
        $this->assertSame('divar', Order::find($q->order_id)->channel);
        $this->assertNotNull($lead->fresh()->user_id);
    }
}
