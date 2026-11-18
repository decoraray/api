<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentMethods;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttachment;
use App\Models\Quote;
use App\Models\Shipping;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use App\Services\Payment\PaymentReceiptRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Concerns\UploadsReceipts;

    private const UPLOAD = '/api/v1/admin/payment-receipts';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('receipts');
    }

    private function staff(string $role = 'Sales'): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function h(User $u): array
    {
        return ['Authorization' => 'Bearer ' . AuthService::createToken($u), 'Accept' => 'application/json'];
    }

    private function upload(User $u, ?UploadedFile $file = null)
    {
        return $this->post(self::UPLOAD, ['file' => $file ?? self::fakeJpeg()], $this->h($u));
    }

    private function quote(User $sales): Quote
    {
        $r = $this->postJson('/api/v1/admin/quotes', [
            'customer_name' => 'علی رضایی',
            'phone' => '09123456789',
            'shipping_mode' => 'on_delivery',
            'lines' => [['description' => 'میز', 'quantity' => 1, 'unit_price' => 10000000]],
        ], $this->h($sales))->assertStatus(201);

        return Quote::findOrFail($r->json('data.id'));
    }

    private function pay(Quote $q, User $u, string $method, array $ids = [])
    {
        return $this->postJson("/api/v1/admin/quotes/{$q->id}/payments", [
            'amount' => 5000000, 'method' => $method, 'paid_at' => '2026-11-18', 'receipt_ids' => $ids,
        ], $this->h($u));
    }

    // ---- upload -------------------------------------------------------------

    public function test_upload_stores_privately_and_returns_signed_link()
    {
        $u = $this->staff();
        $r = $this->upload($u)->assertStatus(201);

        $a = PaymentAttachment::findOrFail($r->json('data.id'));
        $this->assertNull($a->payment_id);
        $this->assertSame($u->id, $a->uploaded_by);
        $this->assertSame('image/jpeg', $a->mime);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f-]{36}\.jpg$#', $a->path);
        $this->assertStringEndsWith('.jpg', $a->path);
        Storage::disk('receipts')->assertExists($a->path);
        Storage::disk('public')->assertMissing($a->path);

        $url = $r->json('data.url');
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }

    public function test_pdf_upload_and_order_creator_can_upload()
    {
        $u = User::factory()->create();
        $u->givePermissionTo('orders.create');
        $this->upload($u, UploadedFile::fake()->createWithContent('cheque.pdf', "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"))
            ->assertStatus(201)->assertJsonPath('data.mime', 'application/pdf');
    }

    public function test_upload_rejects_wrong_type_and_size()
    {
        $u = $this->staff();
        $this->upload($u, UploadedFile::fake()->createWithContent('x.zip', "PK\x03\x04" . str_repeat('a', 100)))->assertStatus(422);
        $this->upload($u, self::fakeJpeg('big.jpg', 6000))->assertStatus(422);
        $this->upload($u, UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertStatus(422);
        $this->assertSame(0, PaymentAttachment::count());
        $this->assertSame([], Storage::disk('receipts')->allFiles());
    }

    public function test_upload_requires_permission()
    {
        $this->upload($this->staff('Technic'))->assertStatus(403);
        $this->post(self::UPLOAD, ['file' => self::fakeJpeg()], ['Accept' => 'application/json'])->assertStatus(401);
        $this->assertSame(0, PaymentAttachment::count());
    }

    // ---- viewing ------------------------------------------------------------

    public function test_signed_link_serves_file_without_jwt_and_without_caching()
    {
        $url = $this->upload($this->staff())->json('data.url');
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        $r = $this->get($path)->assertOk();
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $r->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline', $r->headers->get('Content-Disposition'));
        $this->assertSame('image/jpeg', $r->headers->get('Content-Type'));
    }

    public function test_expired_tampered_or_unsigned_link_is_refused()
    {
        $u = $this->staff();
        $r = $this->upload($u);
        $id = $r->json('data.id');
        $otherId = $this->upload($u)->json('data.id');
        $url = $r->json('data.url');
        $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

        $this->get("/api/v1/admin/payment-receipts/{$id}/file")->assertStatus(403);
        $this->get(str_replace("payment-receipts/{$id}/", "payment-receipts/{$otherId}/", $path))->assertStatus(403);
        $this->get(preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $path))->assertStatus(403);

        $this->travel(PaymentAttachment::LINK_MINUTES + 1)->minutes();
        $this->get($path)->assertStatus(403);
    }

    public function test_receipt_not_reachable_under_public_storage()
    {
        $a = PaymentAttachment::findOrFail($this->upload($this->staff())->json('data.id'));
        $this->get('/storage/' . $a->path)->assertStatus(404);
    }

    // ---- rules ----------------------------------------------------------------

    public function test_rules_require_receipt_only_for_card_and_cheque()
    {
        $rules = app(PaymentReceiptRules::class);
        foreach ([PaymentMethods::CARD, PaymentMethods::CHEQUE] as $m) {
            try {
                $rules->assertAllowed($m, []);
                $this->fail("{$m->name} accepted without receipt");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('receipt_ids', $e->errors());
            }
            $rules->assertAllowed($m, [1]);
        }
        foreach ([PaymentMethods::CASH, PaymentMethods::CASH_RECEIVED, PaymentMethods::OTHER, PaymentMethods::ONLINE, PaymentMethods::PARTIAL] as $m) {
            $rules->assertAllowed($m, []);
        }
        $this->assertTrue(true);
    }

    // ---- quote payments -------------------------------------------------------

    public function test_quote_card_payment_with_receipts()
    {
        $sales = $this->staff();
        $q = $this->quote($sales);
        $ids = [$this->upload($sales)->json('data.id'), $this->upload($sales, UploadedFile::fake()->createWithContent('c.pdf', "%PDF-1.4\n%%EOF\n"))->json('data.id')];

        $r = $this->pay($q, $sales, 'CARD', $ids)->assertStatus(201);

        $payment = Payment::firstOrFail();
        $this->assertEqualsCanonicalizing($ids, $payment->attachments->pluck('id')->all());
        $receipts = $r->json('data.payments.0.receipts');
        $this->assertCount(2, $receipts);
        $this->assertStringContainsString('signature=', $receipts[0]['url']);
    }

    public function test_quote_card_payment_without_receipt_is_refused_and_nothing_created()
    {
        $sales = $this->staff();
        $q = $this->quote($sales);

        $this->pay($q, $sales, 'CARD')->assertStatus(422)->assertJsonValidationErrors('receipt_ids');
        $this->pay($q, $sales, 'CHEQUE')->assertStatus(422)->assertJsonValidationErrors('receipt_ids');
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Order::count());
        $this->assertNull($q->fresh()->order_id);
    }

    public function test_quote_cash_payment_without_receipt_is_accepted()
    {
        $sales = $this->staff();
        $this->pay($this->quote($sales), $sales, 'CASH_RECEIVED')->assertStatus(201)
            ->assertJsonPath('data.payments.0.receipts', []);
    }

    public function test_receipt_cannot_be_reused_or_taken_from_another_user()
    {
        $sales = $this->staff();
        $other = $this->staff();
        $q = $this->quote($sales);

        $mine = $this->upload($sales)->json('data.id');
        $this->pay($q, $sales, 'CARD', [$mine])->assertStatus(201);
        $this->pay($q, $sales, 'CARD', [$mine])->assertStatus(422)->assertJsonValidationErrors('receipt_ids');

        $theirs = $this->upload($other)->json('data.id');
        $this->pay($q, $sales, 'CARD', [$theirs])->assertStatus(422)->assertJsonValidationErrors('receipt_ids');
        $this->assertNull(PaymentAttachment::find($theirs)->payment_id);

        $this->pay($q, $sales, 'CARD', [999999])->assertStatus(422);
        $this->assertSame(1, Payment::count());
    }

    // ---- manual orders --------------------------------------------------------

    private function orderPayload(array $payment): array
    {
        return [
            'phone' => '09120000000',
            'customer_name' => 'علی رضایی',
            'lines' => [['variation_id' => Variation::factory()->create(['price' => 1000000])->id, 'quantity' => 1]],
            'address' => 'تهران',
            'shipping_id' => Shipping::factory()->create()->id,
            'payment_method' => 'CARD',
            'payment' => $payment,
        ];
    }

    public function test_order_card_payment_needs_receipt()
    {
        $sales = $this->staff();

        $this->postJson('/api/v1/admin/orders', $this->orderPayload(['amount' => 3000000, 'method' => 'CARD']), $this->h($sales))
            ->assertStatus(422)->assertJsonValidationErrors('payment.receipt_ids');
        $this->assertSame(0, Order::count());

        $id = $this->upload($sales)->json('data.id');
        $r = $this->postJson('/api/v1/admin/orders', $this->orderPayload(['amount' => 3000000, 'method' => 'CARD', 'receipt_ids' => [$id]]), $this->h($sales))
            ->assertStatus(201);
        $this->assertSame(Payment::firstOrFail()->id, PaymentAttachment::find($id)->payment_id);
        $this->assertCount(1, $r->json('data.payments.0.receipts'));
    }

    public function test_order_with_someone_elses_receipt_rolls_back()
    {
        $sales = $this->staff();
        $id = $this->upload($this->staff())->json('data.id');

        $this->postJson('/api/v1/admin/orders', $this->orderPayload(['amount' => 3000000, 'method' => 'CARD', 'receipt_ids' => [$id]]), $this->h($sales))
            ->assertStatus(422)->assertJsonValidationErrors('payment.receipt_ids');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_order_show_lists_receipts()
    {
        $sales = $this->staff();
        $id = $this->upload($sales)->json('data.id');
        $this->postJson('/api/v1/admin/orders', $this->orderPayload(['amount' => 3000000, 'method' => 'CARD', 'receipt_ids' => [$id]]), $this->h($sales))->assertStatus(201);
        $order = Order::firstOrFail();

        $admin = $this->staff('SuperAdmin');
        $r = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->h($admin))->assertOk();
        $this->assertSame($id, $r->json('data.payments.0.receipts.0.id'));
    }

    // ---- cleanup --------------------------------------------------------------

    public function test_prune_removes_only_old_unattached_receipts()
    {
        $sales = $this->staff();
        $q = $this->quote($sales);

        $this->travel(-25)->hours();
        $old = PaymentAttachment::findOrFail($this->upload($sales)->json('data.id'));
        $oldAttached = $this->upload($sales)->json('data.id');
        $this->travelBack();
        $this->pay($q, $sales, 'CARD', [$oldAttached])->assertStatus(201);
        $fresh = $this->upload($sales)->json('data.id');

        $this->artisan('receipts:prune')->assertSuccessful();

        $this->assertNull(PaymentAttachment::find($old->id));
        Storage::disk('receipts')->assertMissing($old->path);
        $this->assertNotNull(PaymentAttachment::find($oldAttached));
        $this->assertNotNull(PaymentAttachment::find($fresh));
    }
}
