<?php

namespace Tests\Feature\Admin;

use App\Clients\SmsClient\Enums\SmsTemplate;
use App\Clients\SmsClient\SmsProviderClientInterface;
use App\Models\Order;
use App\Models\Quote;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeSms implements SmsProviderClientInterface
{
    public static array $sent = [];

    public function sendSms(string $phone, SmsTemplate $template, array $parameters): void
    {
        self::$sent[] = compact('phone', 'template', 'parameters');
    }
}

class QuoteApiTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Concerns\UploadsReceipts;

    private function header(string $role): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'customer_name' => 'علی رضایی',
            'phone' => '۰۹۱۲۳۴۵۶۷۸۹',
            'address' => 'تهران',
            'shipping_mode' => 'on_delivery',
            'lines' => [
                ['description' => 'جلومبلی', 'dimensions' => '150×90×35', 'color' => 'گردو', 'quantity' => 2, 'unit_price' => 10000000],
            ],
        ], $over);
    }

    private function create(string $role = 'Sales', array $over = []): Quote
    {
        $r = $this->postJson('/api/v1/admin/quotes', $this->payload($over), $this->header($role));
        $r->assertStatus(201);

        return Quote::findOrFail($r->json('data.id') ?? $r->json('id'));
    }

    public function test_store_creates_customer_defaults_and_totals()
    {
        $r = $this->postJson('/api/v1/admin/quotes', $this->payload(), $this->header('Sales'));
        $r->assertStatus(201);
        $j = $r->json('data') ?? $r->json();
        $this->assertSame('draft', $j['status']);
        $this->assertSame(1000 + $j['id'], $j['number']);
        $this->assertSame(60, $j['deposit_percent']);
        $this->assertSame(20000000, $j['totals']['payable']);
        $this->assertSame(12000000, $j['totals']['deposit']);
        $this->assertSame(0, $j['totals']['paid']);
        $this->assertSame(20000000, $j['lines'][0]['line_total']);
        $this->assertSame('09123456789', $j['phone']);
        $this->assertSame([], $j['payments']);
        $this->assertNotNull(User::where('phone', '09123456789')->first());
    }

    public function test_store_reuses_existing_user_and_validates()
    {
        $u = User::factory()->create(['phone' => '09123456789']);
        $q = $this->create();
        $this->assertSame($u->id, $q->user_id);
        $this->assertSame(1, User::where('phone', '09123456789')->count());

        $this->postJson('/api/v1/admin/quotes', ['lines' => []], $this->header('Sales'))->assertStatus(422);
        $this->postJson('/api/v1/admin/quotes', $this->payload(['phone' => '123']), $this->header('Sales'))->assertStatus(422);
        $this->postJson('/api/v1/admin/quotes', $this->payload(['shipping_mode' => 'amount']), $this->header('Sales'))->assertStatus(422);
    }

    public function test_default_notes_and_deposit_from_settings()
    {
        $this->putJson('/api/v1/admin/settings', ['default_notes' => 'N', 'default_deposit_percent' => 50], $this->header('SuperAdmin'))->assertOk();
        $q = $this->create();
        $this->assertSame('N', $q->notes);
        $this->assertSame(50, $q->deposit_percent);
    }

    public function test_technic_is_forbidden()
    {
        $h = $this->header('Technic');
        $this->getJson('/api/v1/admin/quotes', $h)->assertStatus(403);
        $this->postJson('/api/v1/admin/quotes', $this->payload(), $h)->assertStatus(403);
        $this->getJson('/api/v1/admin/customers/lookup?phone=0912', $h)->assertStatus(403);
    }

    public function test_index_search_status_and_show_update()
    {
        $q = $this->create();
        $h = $this->header('Sales');
        $this->create('Sales', ['customer_name' => 'دیگری', 'phone' => '09350000000']);

        $r = $this->getJson('/api/v1/admin/quotes?search=09350', $h)->assertOk();
        $this->assertCount(1, $r->json('data'));
        $this->assertArrayNotHasKey('lines', $r->json('data.0'));
        $this->assertArrayHasKey('totals', $r->json('data.0'));
        $this->assertArrayHasKey('meta', $r->json());
        $this->assertCount(1, $this->getJson('/api/v1/admin/quotes?search=' . $q->number, $h)->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/admin/quotes?status=sent', $h)->json('data'));

        $this->getJson("/api/v1/admin/quotes/{$q->id}", $h)->assertOk()->assertJsonPath('data.id', $q->id);

        $u = $this->putJson("/api/v1/admin/quotes/{$q->id}", $this->payload([
            'lines' => [['description' => 'A', 'quantity' => 1, 'unit_price' => 5], ['description' => 'B', 'quantity' => 3, 'unit_price' => 7]],
        ]), $h)->assertOk();
        $this->assertCount(2, $u->json('data.lines'));
        $this->assertSame(26, $u->json('data.totals.payable'));
        $this->assertSame(2, $q->lines()->count());
    }

    public function test_update_refused_for_paid_and_cancelled()
    {
        $h = $this->header('Sales');
        $q = $this->create();
        $this->postJson("/api/v1/admin/quotes/{$q->id}/payments", ['amount' => 100, 'method' => 'CASH_RECEIVED', 'paid_at' => '2026-11-15'], $h)->assertStatus(201);
        $this->putJson("/api/v1/admin/quotes/{$q->id}", $this->payload(), $h)->assertStatus(422)->assertJsonStructure(['message']);
        $this->postJson("/api/v1/admin/quotes/{$q->id}/cancel", [], $h)->assertStatus(422);

        $c = $this->create();
        $this->postJson("/api/v1/admin/quotes/{$c->id}/cancel", [], $h)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->putJson("/api/v1/admin/quotes/{$c->id}", $this->payload(), $h)->assertStatus(422);
        $this->postJson("/api/v1/admin/quotes/{$c->id}/payments", ['amount' => 100, 'method' => 'CASH_RECEIVED', 'paid_at' => '2026-11-15'], $h)->assertStatus(422);
    }

    public function test_sent_and_public_view()
    {
        $h = $this->header('Sales');
        $q = $this->create();
        $this->postJson("/api/v1/admin/quotes/{$q->id}/sent", [], $h)->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertNotNull($q->fresh()->sent_at);

        $r = $this->getJson("/api/v1/quotes/{$q->token}")->assertOk();
        $j = $r->json();
        foreach (['id', 'token', 'user_id', 'created_by', 'order_id'] as $k) {
            $this->assertArrayNotHasKey($k, $j);
        }
        $this->assertStringNotContainsString($q->token, $r->getContent());
        $this->assertSame('پیش‌فاکتور', $j['title']);
        $this->assertFalse($j['cancelled']);
        $this->assertArrayNotHasKey('id', $j['lines'][0]);

        $this->postJson("/api/v1/admin/quotes/{$q->id}/payments", ['amount' => 100, 'method' => 'CASH', 'paid_at' => '2026-11-15'], $h)->assertStatus(201);
        $this->assertSame('فاکتور', $this->getJson("/api/v1/quotes/{$q->token}")->json('title'));

        $this->getJson('/api/v1/quotes/nope')->assertStatus(404);

        $c = $this->create();
        $this->postJson("/api/v1/admin/quotes/{$c->id}/cancel", [], $h);
        $this->getJson("/api/v1/quotes/{$c->token}")->assertOk()->assertExactJson(['cancelled' => true, 'number' => $c->number]);
    }

    public function test_payments_create_order_and_second_payment_reuses_it()
    {
        $q = $this->create('Sales', ['shipping_mode' => 'amount', 'shipping_amount' => 1000000, 'discount' => 500000]);
        $h = $this->header('Sales');
        $url = "/api/v1/admin/quotes/{$q->id}/payments";

        $r = $this->postJson($url, ['amount' => 12000000, 'method' => 'CARD', 'paid_at' => '2026-11-10', 'reference' => 'R1', 'receipt_ids' => [$this->uploadReceipt($h)]], $h)->assertStatus(201);
        $j = $r->json('data');
        $this->assertSame('deposit_paid', $j['status']);
        $this->assertNotNull($j['order_id']);
        $this->assertSame(20500000, $j['totals']['payable']);
        $this->assertSame(8500000, $j['totals']['remaining']);
        $this->assertSame('CARD', $j['payments'][0]['method']);
        $this->assertSame('کارت به کارت', $j['payments'][0]['method_label']);
        $this->assertSame('R1', $j['payments'][0]['reference']);

        $order = Order::find($j['order_id']);
        $this->assertSame('تایید شده', $order->status);
        $this->assertSame('staff', $order->source);
        $this->assertSame($q->id, $order->quote_id);
        $this->assertSame('CARD', $order->payment_method);
        $this->assertSame(21000000, (int) $order->total);
        $this->assertSame(500000, (int) $order->discount_amount);
        $item = $order->items->first();
        $this->assertSame('custom', $item->slug);
        $this->assertSame('150×90×35', $item->dimensions);
        $this->assertSame(10000000, (int) $item->price);
        $this->assertSame(2, (int) $item->quantity);
        $workOrder = \App\Models\WorkOrder::where('order_id', $order->id)->sole();
        $this->assertSame($item->id, $workOrder->item_id);
        $this->assertSame('waiting', $workOrder->state->value ?? $workOrder->state);

        $r2 = $this->postJson($url, ['amount' => 8500000, 'method' => 'CASH', 'paid_at' => '2026-11-12'], $h)->assertStatus(201);
        $this->assertSame($j['order_id'], $r2->json('data.order_id'));
        $this->assertSame(0, $r2->json('data.totals.remaining'));
        $this->assertCount(2, $r2->json('data.payments'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, \App\Models\WorkOrder::count());

        $this->postJson($url, ['amount' => 1, 'method' => 'CASH', 'paid_at' => '2026-11-12'], $h)->assertStatus(422);
    }

    public function test_overpayment_refused_and_nothing_created()
    {
        $q = $this->create();
        $this->postJson("/api/v1/admin/quotes/{$q->id}/payments", ['amount' => 20000001, 'method' => 'CASH_RECEIVED', 'paid_at' => '2026-11-10'], $this->header('Sales'))
            ->assertStatus(422);
        $this->assertSame(0, Order::count());
        $this->assertNull($q->fresh()->order_id);
        $this->postJson("/api/v1/admin/quotes/{$q->id}/payments", ['amount' => 5, 'method' => 'ONLINE', 'paid_at' => '2026-11-10'], $this->header('Sales'))->assertStatus(422);
    }

    public function test_technic_sees_custom_lines_without_prices_and_order_resources()
    {
        $q = $this->create();
        $this->postJson("/api/v1/admin/quotes/{$q->id}/payments", ['amount' => 100, 'method' => 'CASH_RECEIVED', 'paid_at' => '2026-11-10'], $this->header('Sales'))->assertStatus(201);
        $order = Order::first();
        $h = $this->header('Technic');

        $show = $this->getJson("/api/v1/admin/orders/{$order->id}", $h)->assertOk();
        $d = $show->json('data') ?? $show->json();
        $this->assertSame('staff', $d['source']);
        $this->assertSame($q->id, $d['quote_id']);
        $this->assertSame($q->number, $d['quote_number']);
        $item = $d['items'][0];
        $this->assertSame('جلومبلی', $item['description']);
        $this->assertSame('150×90×35', $item['dimensions']);
        $this->assertSame('گردو', $item['color']);
        $this->assertArrayNotHasKey('price', $item);

        $list = $this->getJson('/api/v1/admin/orders', $h)->assertOk();
        $row = ($list->json('data') ?? $list->json())[0] ?? null;
        $this->assertSame('staff', $row['source'] ?? null);
        $this->assertSame($q->number, $row['quote_number'] ?? null);
    }

    public function test_sms_sends_link_and_marks_sent()
    {
        $this->app->bind(SmsProviderClientInterface::class, FakeSms::class);
        FakeSms::$sent = [];
        $h = $this->header('Sales');
        $q = $this->create();

        $this->postJson("/api/v1/admin/quotes/{$q->id}/sms", [], $h)->assertStatus(422)->assertJsonStructure(['message']);
        $this->assertSame([], FakeSms::$sent);

        config(['services.sms_provider.templates.quote' => '555']);
        $r = $this->postJson("/api/v1/admin/quotes/{$q->id}/sms", [], $h)->assertOk();
        $this->assertNotNull($r->json('data.sms_sent_at'));
        $this->assertSame('sent', $r->json('data.status'));
        $this->assertCount(1, FakeSms::$sent);
        $this->assertSame('09123456789', FakeSms::$sent[0]['phone']);
        $this->assertSame(SmsTemplate::QUOTE_LINK, FakeSms::$sent[0]['template']);
        $this->assertSame(555, SmsTemplate::QUOTE_LINK->templateId());
        $this->assertSame('https://decoraray.com/q/' . $q->token, FakeSms::$sent[0]['parameters']['LINK']);
    }

    public function test_customer_lookup_and_token_not_logged()
    {
        $h = $this->header('Sales');
        $q = $this->create();
        $r = $this->getJson('/api/v1/admin/customers/lookup?phone=' . urlencode('۰۹۱۲۳۴۵۶۷۸۹'), $h)->assertOk();
        $this->assertSame($q->user_id, $r->json('user.id'));
        $this->assertSame('تهران', $r->json('last_address'));
        $this->getJson('/api/v1/admin/customers/lookup?phone=09000000000', $h)->assertOk()->assertJson(['user' => null, 'last_address' => null]);

        $log = \Spatie\Activitylog\Models\Activity::where('subject_type', Quote::class)->get();
        foreach ($log as $a) {
            $this->assertStringNotContainsString($q->token, json_encode($a->properties));
        }
    }

    public function test_line_colour_defaults_to_the_variation_and_is_carried_to_the_order_and_work_order()
    {
        $white = \App\Models\Color::create(['name' => 'سفید']);
        $walnut = \App\Models\Color::create(['name' => 'گردویی']);
        $variation = \App\Models\Variation::factory()->create(['color_id' => $white->id]);
        $h = $this->header('Sales');
        $lines = [
            ['variation_id' => $variation->id, 'product_id' => $variation->product_id, 'description' => 'میز', 'quantity' => 1, 'unit_price' => 18000000],
            ['variation_id' => $variation->id, 'product_id' => $variation->product_id, 'description' => 'میز', 'color_id' => $walnut->id, 'quantity' => 1, 'unit_price' => 18000000],
        ];

        $r = $this->postJson('/api/v1/admin/quotes', $this->payload(['lines' => $lines]), $h)->assertStatus(201);
        $quote = Quote::findOrFail($r->json('data.id') ?? $r->json('id'));
        $this->assertSame([$white->id, $walnut->id], $quote->lines()->orderBy('position')->pluck('color_id')->all());
        // Same price whatever the colour.
        $this->assertSame([18000000, 18000000], $quote->lines()->orderBy('position')->pluck('unit_price')->map(fn ($p) => (int) $p)->all());

        $j = $this->getJson("/api/v1/admin/quotes/{$quote->id}", $h)->json('data');
        $this->assertSame('گردویی', $j['lines'][1]['colour']['name']);

        $this->postJson("/api/v1/admin/quotes/{$quote->id}/payments", ['amount' => 100, 'method' => 'CASH_RECEIVED', 'paid_at' => '2026-11-15'], $h)->assertStatus(201);
        $order = Order::where('quote_id', $quote->id)->sole();
        $this->assertSame([$white->id, $walnut->id], $order->items()->orderBy('id')->pluck('color_id')->all());
        $this->assertEqualsCanonicalizing([$white->id, $walnut->id], \App\Models\WorkOrder::where('order_id', $order->id)->pluck('color_id')->all());

        $this->postJson('/api/v1/admin/quotes', $this->payload(['lines' => [['description' => 'x', 'color_id' => 99999, 'quantity' => 1, 'unit_price' => 1]]]), $h)
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.color_id');
    }
}
