<?php

namespace Tests\Feature\Admin;

use App\Clients\SmsClient\Enums\SmsTemplate;
use App\Clients\SmsClient\SmsProviderClientInterface;
use App\Models\Item;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipping;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CreateOrderFakeSms implements SmsProviderClientInterface
{
    public static array $sent = [];

    public function sendSms(string $phone, SmsTemplate $template, array $parameters): void
    {
        self::$sent[] = compact('phone', 'template', 'parameters');
    }
}

class AdminCreateOrderTest extends TestCase
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
        $variation = $this->variation ??= Variation::factory()->create(['price' => 1000000]);
        $shipping = $this->shipping ??= Shipping::factory()->create(['default_price' => 100000, 'additional_percent' => 0]);

        return array_merge([
            'phone' => '09120000000',
            'customer_name' => 'علی رضایی',
            'lines' => [['variation_id' => $variation->id, 'quantity' => 2]],
            'address' => 'تهران، خیابان آزادی',
            'shipping_id' => $shipping->id,
            'payment_method' => 'CARD',
        ], $over);
    }

    private ?Variation $variation = null;
    private ?Shipping $shipping = null;

    private function submit(array $payload, string $role = 'Sales')
    {
        return $this->postJson('/api/v1/admin/orders', $payload, $this->header($role));
    }

    public function test_creates_order_for_existing_customer()
    {
        $customer = User::factory()->create(['phone' => '09120000000']);

        $r = $this->submit($this->payload());

        $r->assertStatus(201);
        $order = Order::firstOrFail();
        $this->assertSame($customer->id, $order->user_id);
        $this->assertSame('staff', $order->source);
        $this->assertSame(2100000, (int) $order->total);
        $this->assertSame(1, User::where('phone', '09120000000')->count());
    }

    public function test_creates_new_customer_from_name_and_phone()
    {
        $this->submit($this->payload(['phone' => '۰۹۱۲۱۱۱۱۱۱۱']))->assertStatus(201);

        $user = User::where('phone', '09121111111')->firstOrFail();
        $this->assertSame('علی رضایی', $user->name);
        $this->assertSame($user->id, Order::firstOrFail()->user_id);
    }

    public function test_new_customer_requires_a_name()
    {
        $this->submit($this->payload(['customer_name' => null]))->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_custom_line_has_no_product()
    {
        $r = $this->submit($this->payload(['lines' => [['title' => 'میز کنسول سفارشی', 'quantity' => 1, 'unit_price' => 5000000]]]));

        $r->assertStatus(201);
        $item = Item::firstOrFail();
        $this->assertNull($item->product_id);
        $this->assertSame('میز کنسول سفارشی', $item->title);
        $this->assertSame(5100000, (int) Order::firstOrFail()->total);
    }

    public function test_price_override_is_stored()
    {
        $variation = Variation::factory()->create(['price' => 1000000]);

        $this->submit($this->payload(['lines' => [['variation_id' => $variation->id, 'quantity' => 1, 'unit_price' => 900000]]]))
            ->assertStatus(201);

        $this->assertSame(900000, (int) Item::firstOrFail()->price);
    }

    public function test_free_pickup_and_discount()
    {
        $this->submit($this->payload(['shipping_price' => 0, 'discount_amount' => 100000]))->assertStatus(201);

        $order = Order::firstOrFail();
        $this->assertSame(2000000, (int) $order->total);
        $this->assertSame(100000, (int) $order->discount_amount);
    }

    public function test_payment_is_attached_and_no_sms_by_default()
    {
        CreateOrderFakeSms::$sent = [];
        $this->app->bind(SmsProviderClientInterface::class, CreateOrderFakeSms::class);

        $h = $this->header('Sales');
        $receipt = $this->uploadReceipt($h);
        $this->postJson('/api/v1/admin/orders', $this->payload(['payment' => ['amount' => 3000000, 'method' => 'CARD', 'reference' => '123', 'receipt_ids' => [$receipt]]]), $h)
            ->assertStatus(201);

        $order = Order::firstOrFail();
        $this->assertSame('تایید شده', $order->status->value ?? $order->status);
        $payment = Payment::firstOrFail();
        $this->assertSame($order->id, $payment->order_id);
        $this->assertSame(3000000, (int) $payment->amount);
        $this->assertSame('paid', $payment->status);
        $this->assertSame([], CreateOrderFakeSms::$sent);
    }

    public function test_lines_are_required()
    {
        $this->submit($this->payload(['lines' => []]))->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_unknown_channel_is_rejected()
    {
        $this->submit($this->payload(['channel' => 'nope']))->assertStatus(422);
        $this->assertSame(0, Order::count());
    }

    public function test_is_audit_logged_with_the_staff_member()
    {
        $header = $this->header('Sales');
        $this->postJson('/api/v1/admin/orders', $this->payload(), $header)->assertStatus(201);

        $log = Activity::where('description', 'manual-create')->firstOrFail();
        $this->assertNotNull($log->causer_id);
        $this->assertSame(Order::firstOrFail()->id, $log->subject_id);
    }

    public function test_role_without_permission_is_denied()
    {
        $this->submit($this->payload(), 'Technic')->assertStatus(403);
        $this->submit($this->payload(), 'Admin')->assertStatus(403);
        $this->assertSame(0, Order::count());
    }

    public function test_super_admin_can_create()
    {
        $this->submit($this->payload(), 'SuperAdmin')->assertStatus(201);
    }

    public function test_customer_lookup()
    {
        User::factory()->create(['phone' => '09120000000', 'name' => 'سارا']);

        $this->getJson('/api/v1/admin/orders-customer?phone=09120000000', $this->header('Sales'))
            ->assertOk()->assertJsonPath('user.name', 'سارا');
        $this->getJson('/api/v1/admin/orders-customer?phone=09129999999', $this->header('Sales'))
            ->assertOk()->assertJsonPath('user', null);
    }

    public function test_options_lists_choices()
    {
        Shipping::factory()->create();

        $this->getJson('/api/v1/admin/orders-options', $this->header('Sales'))
            ->assertOk()
            ->assertJsonStructure(['shipping_methods' => [['id', 'name', 'price']], 'statuses', 'payment_methods', 'channels'])
            ->assertJsonFragment(['id' => 'direct', 'label' => 'مستقیم']);
        $this->getJson('/api/v1/admin/orders-options', $this->header('Technic'))->assertStatus(403);
    }
}
