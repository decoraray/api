<?php

namespace Tests\Feature\Orders;

use App\Clients\SmsClient\Enums\SmsTemplate;
use App\Clients\SmsClient\SmsProviderClientInterface;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Orders\OrderNotifier;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordingSms implements SmsProviderClientInterface
{
    public static array $sent = [];
    public static bool $fail = false;

    public function sendSms(string $phone, SmsTemplate $template, array $parameters): void
    {
        if (self::$fail) {
            throw new \Exception('provider down');
        }
        self::$sent[] = compact('phone', 'template', 'parameters');
    }
}

class FakeGateway extends PaymentService
{
    public function verify($payment, $gateway)
    {
        $payment->update(['status' => 'paid']);

        return $payment;
    }
}

class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RecordingSms::$sent = [];
        RecordingSms::$fail = false;
        $this->app->bind(SmsProviderClientInterface::class, RecordingSms::class);
        config([
            'app.front_url' => 'https://decoraray.ir',
            'services.sms_provider.templates.order_paid' => '11',
            'services.sms_provider.templates.order_confirmed' => '12',
            'services.sms_provider.templates.order_shipped' => '13',
            'services.sms_provider.templates.order_canceled' => '14',
        ]);
    }

    private function order(array $over = []): Order
    {
        $user = User::factory()->create(['name' => 'مریم رضایی']);

        return Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'uuid' => '6af9d3310f8e9',
            'phone' => '09123456789',
        ], $over));
    }

    private function header(string $role = 'Technic'): array
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($u)];
    }

    private function setStatus(Order $order, string $name, string $role = 'Technic')
    {
        return $this->patchJson("/api/v1/admin/orders/{$order->uuid}", ['status' => $name], $this->header($role));
    }

    public function test_template_ids_from_config_and_null_when_unset()
    {
        foreach (SmsTemplate::cases() as $case) {
            config(['services.sms_provider.templates.' . $case->value => '321']);
            $this->assertSame(321, $case->templateId());
            config(['services.sms_provider.templates.' . $case->value => null]);
            $this->assertNull($case->templateId());
        }
    }

    public function test_event_for_status_mapping()
    {
        $this->assertSame('confirmed', OrderNotifier::eventForStatus(OrderStatus::CONFIRMED));
        $this->assertSame('shipped', OrderNotifier::eventForStatus(OrderStatus::SHIPPING));
        $this->assertSame('canceled', OrderNotifier::eventForStatus(OrderStatus::CANCELED));
        $this->assertNull(OrderNotifier::eventForStatus(OrderStatus::CUTTING));
        $this->assertNull(OrderNotifier::eventForStatus(OrderStatus::DELIVERED));
    }

    public function test_sent_with_exact_parameters()
    {
        $order = $this->order();
        app(OrderNotifier::class)->notifyNow($order, 'shipped');

        $this->assertCount(1, RecordingSms::$sent);
        $s = RecordingSms::$sent[0];
        $this->assertSame('09123456789', $s['phone']);
        $this->assertSame(SmsTemplate::ORDER_SHIPPED, $s['template']);
        $this->assertSame([
            'NAME' => 'مریم',
            'ORDER' => '10f8e9',
            'LINK' => 'https://decoraray.ir/profile/orders#order-6af9d3310f8e9',
        ], $s['parameters']);
        $this->assertSame('sent', $order->notifications()->first()->status);
    }

    public function test_skipped_when_unconfigured()
    {
        config(['services.sms_provider.templates.order_shipped' => null]);
        $order = $this->order();
        app(OrderNotifier::class)->notifyNow($order, 'shipped');

        $this->assertSame([], RecordingSms::$sent);
        $this->assertSame('skipped', $order->notifications()->first()->status);
    }

    public function test_failed_when_client_throws_and_retried_later()
    {
        $order = $this->order();
        RecordingSms::$fail = true;
        app(OrderNotifier::class)->notifyNow($order, 'paid');
        $n = $order->notifications()->first();
        $this->assertSame('failed', $n->status);
        $this->assertSame('provider down', $n->error);

        RecordingSms::$fail = false;
        app(OrderNotifier::class)->notifyNow($order, 'paid');
        $this->assertCount(1, RecordingSms::$sent);
        $this->assertSame(['failed', 'sent'], $order->notifications()->orderBy('id')->pluck('status')->all());
    }

    public function test_second_notify_for_sent_event_does_nothing()
    {
        $order = $this->order();
        app(OrderNotifier::class)->notifyNow($order, 'paid');
        app(OrderNotifier::class)->notifyNow($order, 'paid');

        $this->assertCount(1, RecordingSms::$sent);
        $this->assertSame(1, OrderNotification::count());
    }

    public function test_invalid_phone_is_failed()
    {
        $order = $this->order(['phone' => '123']);
        app(OrderNotifier::class)->notifyNow($order, 'paid');

        $n = $order->notifications()->first();
        $this->assertSame('failed', $n->status);
        $this->assertSame('invalid phone', $n->error);
        $this->assertSame([], RecordingSms::$sent);
    }

    public function test_status_updates_send_for_confirmed_shipping_canceled()
    {
        foreach (['CONFIRMED' => 'confirmed', 'SHIPPING' => 'shipped', 'CANCELED' => 'canceled'] as $name => $event) {
            $order = $this->order(['uuid' => uniqid()]);
            $this->setStatus($order, $name)->assertOk();
            $rows = $order->notifications()->get();
            $this->assertCount(1, $rows);
            $this->assertSame($event, $rows[0]->event);
            $this->assertSame('sent', $rows[0]->status);
        }
        $this->assertCount(3, RecordingSms::$sent);
    }

    public function test_cutting_and_delivered_send_nothing()
    {
        $order = $this->order();
        $this->setStatus($order, 'CUTTING')->assertOk();
        $this->setStatus($order, 'DELIVERED')->assertOk();
        $this->assertSame(0, OrderNotification::count());
        $this->assertSame([], RecordingSms::$sent);
    }

    public function test_same_status_again_and_shipping_back_and_forth_send_once()
    {
        $order = $this->order();
        $this->setStatus($order, 'SHIPPING')->assertOk();
        $this->setStatus($order, 'SHIPPING')->assertOk();
        $this->setStatus($order, 'PACKAGING')->assertOk();
        $this->setStatus($order, 'SHIPPING')->assertOk();

        $this->assertCount(1, RecordingSms::$sent);
        $this->assertSame(1, $order->notifications()->count());
    }

    public function test_status_change_not_broken_by_provider_failure()
    {
        RecordingSms::$fail = true;
        $order = $this->order();
        $this->setStatus($order, 'SHIPPING')->assertOk();

        $this->assertSame('ارسال کالا', $order->fresh()->status);
        $this->assertSame('failed', $order->notifications()->first()->status);
    }

    public function test_status_change_skipped_when_unconfigured()
    {
        config(['services.sms_provider.templates.order_shipped' => null]);
        $order = $this->order();
        $this->setStatus($order, 'SHIPPING')->assertOk();

        $this->assertSame('ارسال کالا', $order->fresh()->status);
        $this->assertSame('skipped', $order->notifications()->first()->status);
    }

    private function payable(): Payment
    {
        // payments.payment_code / card_pan exist in production but in no migration
        foreach (['payment_code', 'card_pan'] as $col) {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('payments', $col)) {
                \Illuminate\Support\Facades\Schema::table('payments', fn ($t) => $t->string($col)->nullable());
            }
        }
        $order = $this->order(['payment_method' => 'online']);
        Cart::create(['user_id' => $order->user_id]);

        return Payment::create([
            'uuid' => 'pay1', 'amount' => 1000, 'user_phone' => '09123456789', 'method' => 'online',
            'user_id' => $order->user_id, 'order_id' => $order->id, 'gateway' => 'saman', 'status' => 'pending',
        ]);
    }

    private function verify()
    {
        $this->app->bind(PaymentService::class, FakeGateway::class);

        return $this->post('/api/v1/payment-verify', ['data' => json_encode(['clientRefId' => 'pay1']), 'gateway' => 'saman']);
    }

    public function test_payment_verify_records_paid_sms()
    {
        $p = $this->payable();
        $this->verify()->assertRedirect(config('app.front_url') . '/payments/success/');

        $this->assertSame('پرداخت شده', $p->order->fresh()->status);
        $rows = $p->order->notifications()->get();
        $this->assertCount(1, $rows);
        $this->assertSame('paid', $rows[0]->event);
        $this->assertSame('sent', $rows[0]->status);
    }

    public function test_payment_verify_survives_provider_failure()
    {
        RecordingSms::$fail = true;
        $p = $this->payable();
        $this->verify()->assertRedirect(config('app.front_url') . '/payments/success/');

        $this->assertSame('پرداخت شده', $p->order->fresh()->status);
        $n = $p->order->notifications()->first();
        $this->assertSame('failed', $n->status);
        $this->assertSame('provider down', $n->error);
    }

    public function test_quote_first_payment_confirms_once()
    {
        $sales = $this->header('Sales');
        $r = $this->postJson('/api/v1/admin/quotes', [
            'customer_name' => 'علی رضایی', 'phone' => '09123456789', 'address' => 'تهران',
            'shipping_mode' => 'on_delivery',
            'lines' => [['description' => 'جلومبلی', 'dimensions' => '1', 'color' => 'x', 'quantity' => 1, 'unit_price' => 1000000]],
        ], $sales)->assertStatus(201);
        $q = Quote::findOrFail($r->json('data.id') ?? $r->json('id'));
        $url = "/api/v1/admin/quotes/{$q->id}/payments";

        $this->postJson($url, ['amount' => 100000, 'method' => 'CARD', 'paid_at' => '2026-11-10'], $sales)->assertStatus(201);
        $order = Order::first();
        $this->assertSame(['confirmed'], $order->notifications()->pluck('event')->all());

        $this->postJson($url, ['amount' => 100000, 'method' => 'CASH', 'paid_at' => '2026-11-11'], $sales)->assertStatus(201);
        $this->assertSame(1, $order->notifications()->count());
        $this->assertCount(1, RecordingSms::$sent);
    }

    public function test_admin_resource_shows_notifications_for_technic()
    {
        $order = $this->order();
        app(OrderNotifier::class)->notifyNow($order, 'paid');
        app(OrderNotifier::class)->notifyNow($order, 'confirmed');

        $r = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->header('Technic'))->assertOk();
        $n = $r->json('data.notifications') ?? $r->json('notifications');
        $this->assertCount(2, $n);
        $this->assertSame('confirmed', $n[0]['event']);
        $this->assertSame('sent', $n[0]['status']);
        $this->assertSame('paid', $n[1]['event']);
        $this->assertArrayHasKey('created_at', $n[0]);
    }
}
