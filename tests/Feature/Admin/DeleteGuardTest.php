<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Color;
use App\Models\Image;
use App\Models\Item;
use App\Models\Material;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        $u = User::factory()->create();
        $u->assignRole('SuperAdmin');

        return ['Authorization' => 'Bearer ' . AuthService::createToken($u), 'Accept' => 'application/json'];
    }

    private function orderLine(Variation $v, ?User $customer = null): Order
    {
        $order = Order::factory()->create($customer ? ['user_id' => $customer->id] : []);
        Item::create([
            'title' => 'میز', 'slug' => 'miz', 'price' => 1000, 'quantity' => 1,
            'product_id' => $v->product_id, 'variation_id' => $v->id, 'order_id' => $order->id,
        ]);

        return $order;
    }

    private function quoteLine(?int $productId, ?int $variationId): void
    {
        $quoteId = DB::table('quotes')->insertGetId([
            'token' => Str::random(40), 'customer_name' => 'x', 'phone' => '0912', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('quote_lines')->insert([
            'quote_id' => $quoteId, 'product_id' => $productId, 'variation_id' => $variationId,
            'description' => 'میز', 'quantity' => 1, 'unit_price' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- products and variations -----------------------------------------------

    public function test_sold_product_is_refused_and_kept_whole()
    {
        $v = Variation::factory()->create();
        $product = $v->product;
        Image::factory()->create(['product_id' => $product->id]);
        DB::table('product_metas')->insert(['product_id' => $product->id, 'key' => 'k', 'value' => 'v', 'created_at' => now(), 'updated_at' => now()]);
        $design = \App\Models\ProductDesign::factory()->create(['product_id' => $product->id]); // holds its BOM and cutting list
        $this->orderLine($v);

        $this->deleteJson("/api/v1/admin/products/{$product->id}", [], $this->admin())
            ->assertStatus(422)
            ->assertJsonValidationErrors('product');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('variations', ['id' => $v->id]);
        $this->assertSame(1, Image::where('product_id', $product->id)->count());
        $this->assertSame(1, DB::table('product_metas')->where('product_id', $product->id)->count());
        $this->assertDatabaseHas('product_designs', ['id' => $design->id]);
    }

    public function test_quoted_product_is_refused()
    {
        $v = Variation::factory()->create();
        $this->quoteLine($v->product_id, null);

        $this->deleteJson("/api/v1/admin/products/{$v->product_id}", [], $this->admin())->assertStatus(422);
        $this->assertDatabaseHas('products', ['id' => $v->product_id]);
    }

    public function test_sold_or_quoted_variation_is_refused()
    {
        $sold = Variation::factory()->create();
        $this->orderLine($sold);
        $quoted = Variation::factory()->create();
        $this->quoteLine(null, $quoted->id);
        $h = $this->admin();

        $this->deleteJson("/api/v1/admin/variations/{$sold->id}", [], $h)->assertStatus(422)->assertJsonValidationErrors('variation');
        $this->deleteJson("/api/v1/admin/variations/{$quoted->id}", [], $h)->assertStatus(422);
        $this->assertDatabaseHas('variations', ['id' => $sold->id]);
        $this->assertDatabaseHas('variations', ['id' => $quoted->id]);
    }

    public function test_unused_product_and_variation_are_still_deleted()
    {
        $v = Variation::factory()->create();
        $other = Variation::factory()->create();
        $h = $this->admin();

        $this->deleteJson("/api/v1/admin/variations/{$v->id}", [], $h)->assertStatus(204);
        $this->deleteJson("/api/v1/admin/products/{$other->product_id}", [], $h)->assertStatus(204);
        $this->assertDatabaseMissing('variations', ['id' => $v->id]);
        $this->assertDatabaseMissing('products', ['id' => $other->product_id]);
    }

    // ---- colors, materials, categories ---------------------------------------

    public function test_color_in_use_is_refused_with_count_instead_of_server_error()
    {
        $color = Color::factory()->create();
        Variation::factory()->count(2)->create(['color_id' => $color->id]);

        $r = $this->deleteJson("/api/v1/admin/colors/{$color->id}", [], $this->admin())->assertStatus(422);
        $this->assertStringContainsString('2', $r->json('errors.color.0'));
        $this->assertDatabaseHas('colors', ['id' => $color->id]);
    }

    public function test_color_used_only_through_pivot_is_refused()
    {
        $color = Color::factory()->create();
        $v = Variation::factory()->create();
        DB::table('color_variation')->insert(['color_id' => $color->id, 'variation_id' => $v->id]);

        $this->deleteJson("/api/v1/admin/colors/{$color->id}", [], $this->admin())->assertStatus(422);
    }

    public function test_material_in_use_is_refused_and_unused_one_deleted()
    {
        $used = Material::factory()->create();
        Variation::factory()->create(['material_id' => $used->id]);
        $onProduct = Material::factory()->create();
        DB::table('material_product')->insert(['material_id' => $onProduct->id, 'product_id' => Product::factory()->create()->id]);
        $unused = Material::factory()->create();
        $h = $this->admin();

        $this->deleteJson("/api/v1/admin/materials/{$used->id}", [], $h)->assertStatus(422)->assertJsonValidationErrors('material');
        $this->deleteJson("/api/v1/admin/materials/{$onProduct->id}", [], $h)->assertStatus(422);
        $this->deleteJson("/api/v1/admin/materials/{$unused->id}", [], $h)->assertStatus(204);
        $this->assertDatabaseMissing('materials', ['id' => $unused->id]);
    }

    public function test_category_with_products_posts_or_children_is_refused()
    {
        $h = $this->admin();
        $withProduct = Category::factory()->create();
        $product = Product::factory()->create();
        $product->categories()->attach($withProduct->id);

        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);

        $withPost = Category::factory()->create();
        $postId = DB::table('posts')->insertGetId(['title' => 't', 'slug' => 's', 'subtitle' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('category_post')->insert(['category_id' => $withPost->id, 'post_id' => $postId]);

        $empty = Category::factory()->create();

        $this->deleteJson("/api/v1/admin/categories/{$withProduct->id}", [], $h)->assertStatus(422)->assertJsonValidationErrors('category');
        $this->deleteJson("/api/v1/admin/categories/{$parent->id}", [], $h)->assertStatus(422);
        $this->deleteJson("/api/v1/admin/categories/{$withPost->id}", [], $h)->assertStatus(422);
        $this->assertSame(1, $product->categories()->count(), 'products keep the category');

        $this->deleteJson("/api/v1/admin/categories/{$empty->id}", [], $h)->assertSuccessful();
        $this->assertDatabaseMissing('categories', ['id' => $empty->id]);
    }

    // ---- customers --------------------------------------------------------------

    public function test_customer_with_orders_is_refused_and_history_kept()
    {
        $customer = User::factory()->create();
        $v = Variation::factory()->create();
        $order = $this->orderLine($v, $customer);
        $payment = Payment::create(['uuid' => (string) Str::uuid(), 'user_phone' => '0912', 'amount' => 1000, 'status' => 'paid', 'order_id' => $order->id, 'user_id' => $customer->id]);
        $workOrder = \App\Models\WorkOrder::factory()->create(['order_id' => $order->id]);

        $this->deleteJson("/api/v1/admin/users/{$customer->id}", [], $this->admin())
            ->assertStatus(422)
            ->assertJsonValidationErrors('user');

        $this->assertDatabaseHas('users', ['id' => $customer->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertSame(2, Item::where('order_id', $order->id)->count(), 'our line and the work order\'s line');
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'order_id' => $order->id]);
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id]);
    }

    public function test_customer_with_only_a_quote_payment_or_lead_is_refused()
    {
        $h = $this->admin();

        $withQuote = User::factory()->create();
        DB::table('quotes')->insert(['token' => Str::random(40), 'customer_name' => 'x', 'phone' => '1', 'user_id' => $withQuote->id, 'created_at' => now(), 'updated_at' => now()]);

        $withPayment = User::factory()->create();
        Payment::create(['uuid' => (string) Str::uuid(), 'user_phone' => '0912', 'amount' => 1, 'status' => 'pending', 'user_id' => $withPayment->id]);

        $withLead = User::factory()->create();
        DB::table('leads')->insert(['name' => 'x', 'source' => 'instagram', 'user_id' => $withLead->id, 'created_at' => now(), 'updated_at' => now()]);

        foreach ([$withQuote, $withPayment, $withLead] as $u) {
            $this->deleteJson("/api/v1/admin/users/{$u->id}", [], $h)->assertStatus(422);
            $this->assertDatabaseHas('users', ['id' => $u->id]);
        }
    }

    public function test_staff_cannot_be_deleted_as_customer()
    {
        $sales = User::factory()->create();
        $sales->assignRole('Sales');

        $this->deleteJson("/api/v1/admin/users/{$sales->id}", [], $this->admin())->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $sales->id]);
    }

    public function test_customer_without_history_is_deleted()
    {
        $u = User::factory()->create();

        $this->deleteJson("/api/v1/admin/users/{$u->id}", [], $this->admin())->assertStatus(204);
        $this->assertDatabaseMissing('users', ['id' => $u->id]);
    }
}
