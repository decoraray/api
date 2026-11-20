<?php

namespace Tests\Feature\CostAdders;

use App\Models\CostAdder;
use App\Models\StockItem;
use Spatie\Activitylog\Models\Activity;

class CostAdderApiTest extends AdderTestCase
{
    private const URL = '/api/v1/admin/cost-adders';

    public function test_create_a_serviceAdder()
    {
        $r = $this->postJson(self::URL, [
            'name' => 'پرس وکیوم', 'group' => 'vacuum', 'basis' => 'service', 'unit' => 'square_metre', 'unit_cost' => 350000,
        ], $this->header('Accounting'))->assertStatus(201);

        $r->assertJsonPath('data.name', 'پرس وکیوم')
            ->assertJsonPath('data.group_label', 'وکیوم')
            ->assertJsonPath('data.unit_label', 'متر مربع')
            ->assertJsonPath('data.unit_cost', 350000)
            ->assertJsonPath('data.active', true);
    }

    public function test_invalid_adders_are_refused()
    {
        $headers = $this->header('Accounting');
        $this->serviceAdder('مگنت درب', 'magnet', 'piece', 20000);

        $this->postJson(self::URL, ['name' => 'مگنت درب', 'group' => 'magnet', 'basis' => 'service', 'unit' => 'piece', 'unit_cost' => 1], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson(self::URL, ['name' => 'رنگ', 'group' => 'colour', 'basis' => 'service', 'unit_cost' => 1], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('unit');
        $this->postJson(self::URL, ['name' => 'رنگ', 'group' => 'colour', 'basis' => 'service', 'unit' => 'piece'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('unit_cost');
        $this->postJson(self::URL, ['name' => 'پیچ', 'group' => 'misc', 'basis' => 'stock'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('stock_item_id');
        $inactive = StockItem::factory()->create(['active' => false]);
        $this->postJson(self::URL, ['name' => 'پیچ', 'group' => 'misc', 'basis' => 'stock', 'stock_item_id' => $inactive->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('stock_item_id');
        $item = StockItem::factory()->create();
        $this->postJson(self::URL, ['name' => 'پیچ', 'group' => 'misc', 'basis' => 'stock', 'stock_item_id' => $item->id, 'unit_cost' => 5], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('unit_cost');

        $this->assertSame(1, CostAdder::count());
    }

    public function test_rails_need_a_stock_item_and_a_length()
    {
        $headers = $this->header('Accounting');
        $r25 = $this->rail(25);
        $r50 = $this->rail(50);

        $this->postJson(self::URL, ['name' => 'ریل ۲۵', 'group' => 'rail', 'basis' => 'stock', 'stock_item_id' => $r25->id, 'length_cm' => 25], $headers)
            ->assertStatus(201)->assertJsonPath('data.length_cm', 25)->assertJsonPath('data.stock_item.code', 'RAIL-25');
        $this->postJson(self::URL, ['name' => 'ریل ۵۰', 'group' => 'rail', 'basis' => 'stock', 'stock_item_id' => $r50->id, 'length_cm' => 50], $headers)
            ->assertStatus(201);

        $this->postJson(self::URL, ['name' => 'ریل بی‌طول', 'group' => 'rail', 'basis' => 'stock', 'stock_item_id' => $r50->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('length_cm');
        $this->postJson(self::URL, ['name' => 'ریل خدمت', 'group' => 'rail', 'basis' => 'service', 'unit' => 'piece', 'unit_cost' => 1, 'length_cm' => 30], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('basis');
        // One active rail per stock item.
        $this->postJson(self::URL, ['name' => 'ریل ۵۰ دوم', 'group' => 'rail', 'basis' => 'stock', 'stock_item_id' => $r50->id, 'length_cm' => 50], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('stock_item_id');

        $this->assertSame([25, 50], CostAdder::orderBy('length_cm')->pluck('length_cm')->all());
    }

    public function test_switching_basis_clears_the_other_fields_and_edits_are_audited()
    {
        $user = $this->staff('Accounting');
        $adder = $this->serviceAdder('پرس وکیوم');

        $this->putJson(self::URL . "/{$adder->id}", [
            'name' => 'پرس وکیوم', 'group' => 'vacuum', 'basis' => 'service', 'unit' => 'square_metre', 'unit_cost' => 400000,
        ], $this->header($user))->assertStatus(200)->assertJsonPath('data.unit_cost', 400000);

        $activity = Activity::where('subject_type', CostAdder::class)->where('event', 'updated')->firstOrFail();
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame(350000, $activity->properties['old']['unit_cost']);
        $this->assertSame(400000, $activity->properties['attributes']['unit_cost']);

        $item = StockItem::factory()->create();
        $this->putJson(self::URL . "/{$adder->id}", ['name' => 'پرس وکیوم', 'group' => 'misc', 'basis' => 'stock', 'stock_item_id' => $item->id], $this->header($user))
            ->assertStatus(200);
        $adder->refresh();
        $this->assertNull($adder->unit);
        $this->assertNull($adder->unit_cost);
    }

    public function test_list_orders_by_group_filters_and_searches()
    {
        $rail = $this->stockAdder('ریل ۵۰', $this->rail(50), 'rail', 50);
        $rail2 = $this->stockAdder('ریل ۲۵', $this->rail(25), 'rail', 25);
        $vacuum = $this->serviceAdder('پرس وکیوم');
        $pu = $this->serviceAdder('رنگ پلی‌یورتان', 'colour');
        $acrylic = $this->serviceAdder('رنگ اکریلیک', 'colour');
        $old = $this->serviceAdder('قدیمی', 'misc', 'piece', 1, ['active' => false]);
        $headers = $this->header('Technic');

        $ids = fn (string $q) => collect($this->getJson(self::URL . "?{$q}", $headers)->assertStatus(200)->json('data'))->pluck('id')->all();

        // rail, then colour, then vacuum (enum order); by name within a group.
        $this->assertSame([$rail2->id, $rail->id, $acrylic->id, $pu->id, $vacuum->id], $ids(''));
        $this->assertSame([$rail2->id, $rail->id], $ids('group=rail'));
        $this->assertSame([$pu->id], $ids('search=' . urlencode('پلی')));
        $this->assertSame([$old->id], $ids('active=0'));
        $this->assertCount(6, $ids('active=all'));

        $r = $this->getJson(self::URL . '?group=rail', $headers);
        $this->assertSame([25, 50], array_column($r->json('data'), 'length_cm'));
        $this->assertContains(['id' => 'vacuum', 'name' => 'وکیوم'], $r->json('groups'));
    }

    public function test_cost_is_shown_only_with_costs_view()
    {
        $this->serviceAdder('پرس وکیوم');

        $technic = $this->getJson(self::URL, $this->header('Technic'))->assertStatus(200)->json('data.0');
        $this->assertSame('پرس وکیوم', $technic['name']);
        $this->assertSame('vacuum', $technic['group']);
        $this->assertSame('square_metre', $technic['unit']);
        $this->assertArrayNotHasKey('unit_cost', $technic);

        $accounting = $this->staff('Accounting');
        $accounting->givePermissionTo('production.view');
        $this->getJson(self::URL, $this->header($accounting))->assertStatus(200)->assertJsonPath('data.0.unit_cost', 350000);
    }

    public function test_who_may_list_and_write()
    {
        $adder = $this->serviceAdder('پرس وکیوم');
        $body = ['name' => 'رنگ', 'group' => 'colour', 'basis' => 'service', 'unit' => 'piece', 'unit_cost' => 1];

        $this->getJson(self::URL, $this->header('Warehouse'))->assertStatus(403);
        $this->getJson(self::URL, $this->header('Sales'))->assertStatus(200);
        $this->postJson(self::URL, $body, $this->header('Technic'))->assertStatus(403);
        $this->putJson(self::URL . "/{$adder->id}", $body, $this->header('Technic'))->assertStatus(403);
        $this->assertSame(1, CostAdder::count());
    }
}
