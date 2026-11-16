<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\Color;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WholesalePricesTest extends TestCase
{
    use RefreshDatabase;

    private function header(string $role): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    /** Role with catalog.view/edit, prices.view, quotes.manage, settings.manage but NOT prices.wholesale. */
    private function tempHeader(): array
    {
        $role = Role::create(['name' => 'TempNoWholesale', 'guard_name' => 'web']);
        $role->givePermissionTo(['catalog.view', 'catalog.edit', 'prices.view', 'quotes.manage', 'settings.manage']);

        return $this->header('TempNoWholesale');
    }

    private function variation(array $over = []): Variation
    {
        $v = Variation::factory()->create(array_merge(['price' => 12000000, 'wholesale_price' => 9500000], $over));
        $v->colors()->sync([$v->color_id]);

        return $v;
    }

    private function hasWholesaleKey($data): bool
    {
        foreach ((array) $data as $k => $v) {
            if (is_string($k) && stripos($k, 'wholesale') !== false) {
                return true;
            }
            if (is_array($v) && $this->hasWholesaleKey($v)) {
                return true;
            }
        }

        return false;
    }

    // ---- 1.2 exposure ----

    public function test_admin_product_variations_gated_by_permission()
    {
        $v = $this->variation();
        foreach (['SuperAdmin', 'Sales'] as $role) {
            $r = $this->getJson("/api/v1/admin/products/{$v->product_id}", $this->header($role))->assertOk();
            $this->assertSame(9500000, $r->json('variations.0.wholesale_price'), $role);
            $this->assertSame(9500000, $r->json('variations.0.effective_wholesale_price'));
        }
        $this->assertSame(10200000, $this->variation(['wholesale_price' => null, 'product_id' => $v->product_id])
            ->effectiveWholesalePrice());

        foreach (['Technic'] as $role) {
            $r = $this->getJson("/api/v1/admin/products/{$v->product_id}", $this->header($role))->assertOk();
            $this->assertFalse($this->hasWholesaleKey($r->json()), $role);
        }
        $h = $this->tempHeader();
        $r = $this->getJson("/api/v1/admin/products/{$v->product_id}", $h)->assertOk();
        $this->assertSame(12000000, $r->json('variations.0.old_price'));
        $this->assertFalse($this->hasWholesaleKey($r->json()));
        $this->assertFalse($this->hasWholesaleKey($this->getJson("/api/v1/admin/variations/{$v->id}", $h)->json()));
        $this->assertTrue($this->hasWholesaleKey($this->getJson("/api/v1/admin/variations/{$v->id}", $this->header('SuperAdmin'))->json()));
    }

    public function test_public_endpoints_never_contain_wholesale()
    {
        $v = $this->variation();
        $product = $v->product;
        $product->categories()->attach($cat = Category::factory()->create());

        $urls = [
            "/api/v1/products/{$product->slug}", "/api/v1/products/{$product->id}", '/api/v1/products',
            '/api/v1/all-products', '/api/v1/initials', "/api/v1/categories/{$cat->slug}",
        ];
        foreach ($urls as $url) {
            $r = $this->getJson($url);
            $this->assertLessThan(500, $r->status(), $url);
            $this->assertFalse($this->hasWholesaleKey(json_decode($r->getContent(), true)), $url);
            $this->assertStringNotContainsStringIgnoringCase('wholesale', $r->getContent(), $url);
        }

        $product->load('variations');
        $req = request();
        foreach ([
            \App\Http\Resources\ProductShowResource::class, \App\Http\Resources\ProductListResource::class,
            \App\Http\Resources\ProductLightShowResource::class, \App\Http\Resources\ProductCartResource::class,
        ] as $cls) {
            $subject = $product;
            if ($cls === \App\Http\Resources\ProductCartResource::class) {
                $subject = $v->fresh();
                $subject->setRelation('pivot', (object) ['quantity' => 1, 'description' => null]);
            }
            $json = json_encode((new $cls($subject))->resolve($req));
            $this->assertStringNotContainsStringIgnoringCase('wholesale', $json, $cls);
        }
        $this->assertStringNotContainsStringIgnoringCase('wholesale', json_encode((new \App\Http\Resources\VariationShowResource($v))->resolve($req)));
    }

    public function test_settings_read_and_write_gated()
    {
        $this->assertSame(15, $this->getJson('/api/v1/admin/settings', $this->header('Sales'))->json('wholesale_discount_percent'));
        $this->assertSame(15, $this->getJson('/api/v1/admin/settings', $this->header('SuperAdmin'))->json('wholesale_discount_percent'));
        $h = $this->tempHeader();
        $this->assertArrayNotHasKey('wholesale_discount_percent', $this->getJson('/api/v1/admin/settings', $h)->json());

        $this->putJson('/api/v1/admin/settings', ['wholesale_discount_percent' => 20], $h)->assertStatus(403);
        $this->putJson('/api/v1/admin/settings', ['wholesale_discount_percent' => 91], $this->header('SuperAdmin'))->assertStatus(422);
        $this->putJson('/api/v1/admin/settings', ['wholesale_discount_percent' => '۲۰'], $this->header('SuperAdmin'))
            ->assertOk()->assertJsonPath('wholesale_discount_percent', 20);

        $log = Activity::where('log_name', 'settings')->latest('id')->first();
        $this->assertSame(['key' => 'wholesale_discount_percent', 'old' => null, 'new' => 20], $log->properties->only(['key', 'old', 'new'])->all());
    }

    // ---- 1.3 writing & preservation ----

    private function arrayPayload(Variation $v, array $over = []): array
    {
        return [array_merge([
            'price' => 12000000, 'size' => $v->size, 'material_id' => $v->material_id,
            'colors' => [$v->color->name], 'stock' => 1, 'active' => true,
        ], $over)];
    }

    public function test_set_and_clear_with_permission()
    {
        $v = $this->variation(['wholesale_price' => null]);
        $h = $this->header('SuperAdmin');

        $r = $this->postJson("/api/v1/admin/products/{$v->product_id}/variations", $this->arrayPayload($v, ['wholesale_price' => '۹٬۵۰۰٬۰۰۰']), $h)->assertOk();
        $this->assertSame(9500000, $r->json('variations.0.wholesale_price'));
        $this->assertSame(12000000, $r->json('variations.0.old_price'));

        $this->postJson("/api/v1/admin/products/{$v->product_id}/variations", $this->arrayPayload($v, ['wholesale_price' => null]), $h)->assertOk();
        $this->assertNull(Variation::where('product_id', $v->product_id)->first()->wholesale_price);

        $this->putJson("/api/v1/admin/variations/{$v->id}", [], $h);
        $one = Variation::where('product_id', $v->product_id)->first();
        $this->putJson("/api/v1/admin/variations/{$one->id}", ['wholesale_price' => 8000000], $h)->assertOk();
        $this->assertSame(8000000, $one->fresh()->wholesale_price);
        $this->putJson("/api/v1/admin/variations/{$one->id}", ['wholesale_price' => null], $h)->assertOk();
        $this->assertNull($one->fresh()->wholesale_price);
    }

    public function test_without_permission_field_is_ignored_and_not_returned()
    {
        $v = $this->variation();
        $h = $this->tempHeader();

        $r = $this->putJson("/api/v1/admin/variations/{$v->id}", ['wholesale_price' => 1], $h)->assertOk();
        $this->assertFalse($this->hasWholesaleKey($r->json()));
        $this->assertSame(9500000, $v->fresh()->wholesale_price);

        $new = $this->postJson('/api/v1/admin/variations', [
            'product_id' => $v->product_id, 'colors' => [$v->color->name], 'price' => 100, 'stock' => 1, 'active' => true, 'wholesale_price' => 5,
        ], $h)->assertSuccessful();
        $this->assertNull(Variation::find($new->json('id'))->wholesale_price);
    }

    public function test_array_store_preserves_for_matching_and_nulls_new()
    {
        $v = $this->variation();
        $h = $this->tempHeader();
        $payload = array_merge(
            $this->arrayPayload($v, ['wholesale_price' => 1]),
            [['price' => 5000000, 'size' => 'ZZ', 'colors' => [$v->color->name], 'stock' => 1, 'wholesale_price' => 2]]
        );

        $r = $this->postJson("/api/v1/admin/products/{$v->product_id}/variations", $payload, $h)->assertOk();
        $this->assertFalse($this->hasWholesaleKey($r->json()));
        $rows = Variation::where('product_id', $v->product_id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(9500000, $rows[0]->wholesale_price);
        $this->assertNull($rows[1]->wholesale_price);
    }

    public function test_audit_log_records_wholesale_change()
    {
        $v = $this->variation();
        $this->putJson("/api/v1/admin/variations/{$v->id}", ['wholesale_price' => 9000000], $this->header('SuperAdmin'))->assertOk();
        $a = Activity::where('subject_type', Variation::class)->where('subject_id', $v->id)->latest('id')->first();
        $this->assertEquals(9500000, $a->properties['old']['wholesale_price']);
        $this->assertEquals(9000000, $a->properties['attributes']['wholesale_price']);
    }

    // ---- 1.4 quote tier ----

    private function quotePayload(array $over = []): array
    {
        return array_merge([
            'customer_name' => 'علی', 'phone' => '09123456789', 'shipping_mode' => 'on_delivery',
            'lines' => [['description' => 'x', 'quantity' => 2, 'unit_price' => 9500000]],
        ], $over);
    }

    public function test_wholesale_quote_requires_permission()
    {
        $this->postJson('/api/v1/admin/quotes', $this->quotePayload(['price_tier' => 'wholesale']), $this->tempHeader())
            ->assertStatus(422)->assertJsonValidationErrors('price_tier');
        $this->assertSame(0, Quote::count());

        $r = $this->postJson('/api/v1/admin/quotes', $this->quotePayload(['price_tier' => 'wholesale']), $this->header('Sales'))->assertCreated();
        $this->assertSame('wholesale', Quote::first()->price_tier);
        $this->assertSame('wholesale', $r->json('data.price_tier') ?? $r->json('price_tier'));

        $id = Quote::first()->id;
        $this->putJson("/api/v1/admin/quotes/{$id}", $this->quotePayload(['price_tier' => 'retail']), $this->header('Sales'))->assertOk();
        $this->assertSame('retail', Quote::first()->price_tier);
    }

    public function test_quote_defaults_to_retail()
    {
        $this->postJson('/api/v1/admin/quotes', $this->quotePayload(), $this->tempHeader())->assertCreated();
        $this->assertSame('retail', Quote::first()->price_tier);
    }

    public function test_wholesale_quote_amounts_hidden_without_permission()
    {
        $this->postJson('/api/v1/admin/quotes', $this->quotePayload(['price_tier' => 'wholesale']), $this->header('Sales'))->assertCreated();
        $q = Quote::first();
        $h = $this->tempHeader();

        $show = $this->getJson("/api/v1/admin/quotes/{$q->id}", $h)->assertOk()->json('data');
        $this->assertSame('wholesale', $show['price_tier']);
        $this->assertArrayNotHasKey('totals', $show);
        $this->assertArrayNotHasKey('unit_price', $show['lines'][0]);
        $this->assertArrayNotHasKey('line_total', $show['lines'][0]);
        $this->assertSame([], $show['payments']);

        $list = $this->getJson('/api/v1/admin/quotes', $h)->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('totals', $list);

        $full = $this->getJson("/api/v1/admin/quotes/{$q->id}", $this->header('Sales'))->json('data');
        $this->assertSame(9500000, $full['lines'][0]['unit_price']);
        $this->assertArrayHasKey('totals', $full);

        // retail quotes unaffected
        $this->postJson('/api/v1/admin/quotes', $this->quotePayload(), $this->header('Sales'))->assertCreated();
        $retail = Quote::where('price_tier', 'retail')->first();
        $this->assertArrayHasKey('totals', $this->getJson("/api/v1/admin/quotes/{$retail->id}", $h)->json('data'));
    }
}
