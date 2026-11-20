<?php

namespace Tests\Feature\Admin;

use App\Models\Deal;
use App\Models\Product;
use App\Models\User;
use App\Models\Variation;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealCrudTest extends TestCase
{
    use RefreshDatabase;

    private function h(string $role = 'SuperAdmin'): array
    {
        $u = tap(User::factory()->create(), fn ($x) => $x->assignRole($role));

        return ['Authorization' => 'Bearer ' . AuthService::createToken($u), 'Accept' => 'application/json'];
    }

    private function product(int $price = 1000000): Product
    {
        return Variation::factory()->create(['price' => $price])->product;
    }

    public function test_create_with_dates_and_products_then_show_and_list()
    {
        $h = $this->h();
        $a = $this->product();
        $b = $this->product();

        $id = $this->postJson('/api/v1/admin/deals', [
            'slug' => 'yalda', 'description' => 'یلدا', 'discount' => 20, 'start_date' => '2026-12-20', 'end_date' => '2027-01-05', 'products' => [$a->id, $b->id],
        ], $h)->assertCreated()->assertJsonCount(2, 'data.products')->json('data.id');

        $this->getJson("/api/v1/admin/deals/{$id}", $h)->assertOk()->assertJsonPath('data.slug', 'yalda');
        $this->getJson('/api/v1/admin/deals', $h)->assertOk()->assertJsonPath('data.0.products_count', 2);

        $deal = Deal::findOrFail($id);
        $this->assertSame('2026-12-20', substr((string) $deal->start_date, 0, 10));
        $this->assertSame($id, $a->fresh()->deal_id);
    }

    public function test_update_accepts_start_date_and_end_date_and_old_names()
    {
        $h = $this->h();
        $deal = Deal::create(['slug' => 'd', 'description' => 'd', 'discount' => 10, 'active' => 1]);

        $this->patchJson("/api/v1/admin/deals/{$deal->id}", ['end_date' => '2027-01-20'], $h)->assertOk();
        $this->assertSame('2027-01-20', substr((string) $deal->fresh()->end_date, 0, 10));

        $this->patchJson("/api/v1/admin/deals/{$deal->id}", ['start' => '2027-01-01'], $h)->assertOk();
        $this->assertSame('2027-01-01', substr((string) $deal->fresh()->start_date, 0, 10));
        $this->assertSame('2027-01-20', substr((string) $deal->fresh()->end_date, 0, 10), 'end date untouched');
    }

    public function test_products_list_is_synced_on_update()
    {
        $h = $this->h();
        $deal = Deal::create(['slug' => 'd', 'description' => 'd', 'discount' => 10, 'active' => 1]);
        $keep = $this->product();
        $drop = $this->product();
        $add = $this->product();
        Product::whereIn('id', [$keep->id, $drop->id])->update(['deal_id' => $deal->id]);

        $this->patchJson("/api/v1/admin/deals/{$deal->id}", ['products' => [$keep->id, $add->id]], $h)->assertOk();

        $this->assertSame($deal->id, $keep->fresh()->deal_id);
        $this->assertSame($deal->id, $add->fresh()->deal_id);
        $this->assertNull($drop->fresh()->deal_id);
    }

    public function test_deactivating_restores_normal_price()
    {
        $h = $this->h();
        $p = $this->product(1000000);
        $deal = Deal::create(['slug' => 'd', 'description' => 'd', 'discount' => 20, 'active' => 1]);
        Product::whereKey($p->id)->update(['deal_id' => $deal->id]);
        $this->assertEquals(800000, $p->fresh()->averagePrice);

        $this->patchJson("/api/v1/admin/deals/{$deal->id}", ['active' => false], $h)->assertOk();
        $this->assertEquals(1000000, $p->fresh()->averagePrice);
    }

    public function test_delete_frees_products_and_unknown_id_is_404()
    {
        $h = $this->h();
        $p = $this->product();
        $deal = Deal::create(['slug' => 'd', 'description' => 'd', 'discount' => 20, 'active' => 1]);
        Product::whereKey($p->id)->update(['deal_id' => $deal->id]);

        $this->deleteJson("/api/v1/admin/deals/{$deal->id}", [], $h)->assertNoContent();
        $this->assertNull(Deal::find($deal->id));
        $this->assertNull($p->fresh()->deal_id);

        $this->deleteJson('/api/v1/admin/deals/99999', [], $h)->assertNotFound();
        $this->patchJson('/api/v1/admin/deals/99999', ['active' => true], $h)->assertNotFound();
    }

    public function test_catalog_view_reads_but_cannot_change()
    {
        $deal = Deal::create(['slug' => 'd', 'description' => 'd', 'discount' => 20, 'active' => 1]);
        $h = $this->h('Sales');

        $this->getJson('/api/v1/admin/deals', $h)->assertOk();
        $this->deleteJson("/api/v1/admin/deals/{$deal->id}", [], $h)->assertForbidden();
    }
}
