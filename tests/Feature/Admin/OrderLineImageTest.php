<?php

namespace Tests\Feature\Admin;

use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItemImage;
use App\Models\Shipping;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderLineImageTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Concerns\UploadsReceipts;

    private const UPLOAD = '/api/v1/admin/order-line-images';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('order-line-images');
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
        return $this->post(self::UPLOAD, ['file' => $file ?? self::fakeJpeg('طرح.jpg')], $this->h($u));
    }

    private function uploadId(User $u): int
    {
        return $this->upload($u)->assertStatus(201)->json('data.id');
    }

    private function order(User $u, array ...$lineImages)
    {
        return $this->postJson('/api/v1/admin/orders', [
            'phone' => '09120000000',
            'customer_name' => 'علی رضایی',
            'lines' => array_map(fn ($ids) => ['title' => 'میز کنسول سفارشی', 'quantity' => 1, 'unit_price' => 5000000, 'image_ids' => $ids], $lineImages),
            'address' => 'تهران',
            'shipping_id' => Shipping::factory()->create()->id,
            'payment_method' => 'CARD',
        ], $this->h($u));
    }

    private function path(string $url): string
    {
        return parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
    }

    // ---- upload -------------------------------------------------------------

    public function test_upload_stores_privately_and_returns_signed_link()
    {
        $u = $this->staff();
        $r = $this->upload($u)->assertStatus(201)
            ->assertJsonPath('data.mime', 'image/jpeg')
            ->assertJsonPath('data.name', 'طرح.jpg');

        $image = OrderItemImage::findOrFail($r->json('data.id'));
        $this->assertNull($image->item_id);
        $this->assertSame($u->id, $image->uploaded_by);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f-]{36}\.jpg$#', $image->path);
        Storage::disk('order-line-images')->assertExists($image->path);
        $this->assertStringContainsString('signature=', $r->json('data.url'));
    }

    public function test_upload_rejects_pdf_large_and_script_files()
    {
        $u = $this->staff();
        $pdf = UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n%%EOF\n");
        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->upload($u, $pdf)->assertStatus(422);
        $this->upload($u, self::fakeJpeg('big.jpg', 6000))->assertStatus(422);
        $this->upload($u, $svg)->assertStatus(422);
        $this->assertSame(0, OrderItemImage::count());
        $this->assertSame([], Storage::disk('order-line-images')->allFiles());
    }

    public function test_upload_requires_orders_create()
    {
        $this->upload($this->staff('Technic'))->assertStatus(403);
        $this->post(self::UPLOAD, ['file' => self::fakeJpeg()], ['Accept' => 'application/json'])->assertStatus(401);
        $this->assertSame(0, OrderItemImage::count());
    }

    public function test_file_is_served_by_signed_link_only()
    {
        $r = $this->upload($this->staff());
        $path = $this->path($r->json('data.url'));

        $file = $this->get($path)->assertOk();
        $this->assertSame('image/jpeg', $file->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $file->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $file->headers->get('X-Content-Type-Options'));

        $this->get(self::UPLOAD . "/{$r->json('data.id')}/file")->assertStatus(403);
        $this->travel(OrderItemImage::LINK_MINUTES + 1)->minutes();
        $this->get($path)->assertStatus(403);
    }

    public function test_deleting_the_row_removes_the_file()
    {
        $image = OrderItemImage::findOrFail($this->uploadId($this->staff()));
        $image->delete();

        Storage::disk('order-line-images')->assertMissing($image->path);
    }

    // ---- attaching ------------------------------------------------------------

    public function test_order_attaches_images_in_the_given_order()
    {
        $u = $this->staff();
        [$a, $b, $c] = [$this->uploadId($u), $this->uploadId($u), $this->uploadId($u)];

        $this->order($u, [$b, $a], [$c])->assertStatus(201);

        [$first, $second] = Item::orderBy('id')->get()->all();
        $this->assertSame([$b, $a], $first->images->pluck('id')->all());
        $this->assertSame([$c], $second->images->pluck('id')->all());
    }

    public function test_used_missing_foreign_or_repeated_images_are_refused()
    {
        $u = $this->staff();
        $used = $this->uploadId($u);
        $this->order($u, [$used])->assertStatus(201);
        $free = $this->uploadId($u);
        $theirs = $this->uploadId($this->staff());

        $this->order($u, [], [$used])->assertStatus(422)->assertJsonValidationErrors('lines.1.image_ids');
        $this->order($u, [999999])->assertStatus(422)->assertJsonValidationErrors('lines.0.image_ids');
        $this->order($u, [$theirs])->assertStatus(422)->assertJsonValidationErrors('lines.0.image_ids');
        $this->order($u, [$free], [$free])->assertStatus(422)->assertJsonValidationErrors('lines.1.image_ids');
        $this->order($u, [$free, $free])->assertStatus(422)->assertJsonValidationErrors('lines.0.image_ids');

        $this->assertSame(1, Order::count());
        $this->assertNull(OrderItemImage::find($free)->item_id);
    }

    public function test_more_than_five_images_are_refused()
    {
        $u = $this->staff();
        $ids = array_map(fn () => $this->uploadId($u), range(1, 6));

        $this->order($u, $ids)->assertStatus(422)->assertJsonValidationErrors('lines.0.image_ids');
        $this->assertSame(0, Order::count());
    }

    // ---- reading --------------------------------------------------------------

    public function test_order_and_work_order_show_the_images()
    {
        $u = $this->staff();
        $id = $this->uploadId($u);
        $created = $this->order($u, [$id], [])->assertStatus(201);
        $this->assertSame($id, $created->json('data.items.0.images.0.id'));

        $admin = $this->staff('SuperAdmin');
        $order = Order::firstOrFail();
        $show = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->h($admin))->assertOk();
        $this->assertStringContainsString('signature=', $show->json('data.items.0.images.0.url'));
        $this->assertSame([], $show->json('data.items.1.images'));

        [$with, $without] = WorkOrder::orderBy('item_id')->get()->all();
        $technic = $this->staff('Technic');
        $r = $this->getJson("/api/v1/admin/work-orders/{$with->id}", $this->h($technic))->assertOk();
        $this->assertSame($id, $r->json('data.images.0.id'));
        $this->get($this->path($r->json('data.images.0.url')))->assertOk();
        $this->getJson("/api/v1/admin/work-orders/{$without->id}", $this->h($technic))->assertOk()
            ->assertJsonPath('data.images', []);
    }

    public function test_customer_order_list_has_no_reference_images()
    {
        $u = $this->staff();
        $this->order($u, [$this->uploadId($u)])->assertStatus(201);
        $customer = Order::firstOrFail()->user;

        $r = $this->getJson('/api/v1/orders', $this->h($customer))->assertOk();

        $this->assertStringContainsString('میز کنسول سفارشی', json_encode($r->json(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('order-line-images', $r->getContent());
        $this->assertStringNotContainsString('"images"', $r->getContent());
    }

    // ---- cleanup --------------------------------------------------------------

    public function test_prune_removes_only_old_unattached_images()
    {
        $u = $this->staff();
        $this->travel(-25)->hours();
        $old = OrderItemImage::findOrFail($this->uploadId($u));
        $attached = $this->uploadId($u);
        $this->travelBack();
        $this->order($u, [$attached])->assertStatus(201);
        $fresh = $this->uploadId($u);

        $this->artisan('receipts:prune')->assertSuccessful();

        $this->assertNull(OrderItemImage::find($old->id));
        Storage::disk('order-line-images')->assertMissing($old->path);
        $this->assertNotNull(OrderItemImage::find($attached));
        $this->assertNotNull(OrderItemImage::find($fresh));
    }
}
