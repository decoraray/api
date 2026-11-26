<?php

namespace Tests\Feature\Inventory;

use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class StockItemApiTest extends InventoryTestCase
{
    private function mdf(array $over = []): array
    {
        return array_merge([
            'code' => 'MDF-16-WH',
            'name' => 'MDF سفید ۱۶',
            'kind' => 'mdf_sheet',
            'unit' => 'sheet',
            'thickness_mm' => 16,
            'color' => 'سفید',
            'length_mm' => 3660,
            'width_mm' => 1830,
            'reorder_point' => 10,
        ], $over);
    }

    // --- list and show ----------------------------------------------------------

    public function test_list_filters_and_defaults_to_active_items_by_name()
    {
        $sheet = $this->stocked(4, 0, ['name' => 'ب ورق', 'kind' => 'mdf_sheet', 'unit' => 'sheet', 'color' => 'گردویی', 'reorder_point' => 10]);
        $hinge = $this->stocked(50, 0, ['name' => 'الف لولا', 'code' => 'HNG-77', 'reorder_point' => 10]);
        $old = StockItem::factory()->create(['name' => 'ج قدیمی', 'active' => false]);
        $headers = $this->header('Warehouse');

        $ids = fn (string $query) => collect($this->getJson("/api/v1/admin/stock-items?{$query}", $headers)->assertStatus(200)->json('data'))->pluck('id')->all();

        $this->assertSame([$hinge->id, $sheet->id], $ids(''));
        $this->assertSame([$hinge->id], $ids('kind=hardware'));
        $this->assertSame([$sheet->id], $ids('low=1'));
        $this->assertSame([$old->id], $ids('active=0'));
        $this->assertCount(3, $ids('active=all'));
        $this->assertSame([$hinge->id], $ids('search=HNG-77'));
        $this->assertSame([$sheet->id], $ids('search=' . urlencode('گردویی')));

        $r = $this->getJson('/api/v1/admin/stock-items', $headers);
        $this->assertSame(1, $r->json('low_count'));
        $this->assertContains(['id' => 'mdf_sheet', 'name' => 'ورق MDF'], $r->json('kinds'));
        $this->assertContains(['id' => 'sheet', 'name' => 'ورق'], $r->json('units'));
        $this->assertTrue($r->json('data.1.is_low'));
    }

    public function test_costs_are_only_for_users_with_costs_view()
    {
        $item = $this->stocked(10, 1100000);
        $warehouse = $this->header('Warehouse');
        $withCosts = $this->header($this->staff('Warehouse', ['costs.view']));

        foreach (["/api/v1/admin/stock-items", "/api/v1/admin/stock-items/{$item->id}", "/api/v1/admin/stock-items/{$item->id}/movements"] as $url) {
            $json = json_encode($this->getJson($url, $warehouse)->assertStatus(200)->json());
            $this->assertStringNotContainsString('cost', $json, $url);
            $this->assertStringNotContainsString('value', $json, $url);
            $this->assertStringNotContainsString('1100000', $json, $url);
        }

        $this->getJson("/api/v1/admin/stock-items/{$item->id}", $withCosts)
            ->assertJsonPath('data.average_cost', 1100000)
            ->assertJsonPath('data.stock_value', 11000000);
        $this->getJson("/api/v1/admin/stock-items/{$item->id}/movements", $withCosts)
            ->assertJsonPath('data.0.unit_cost', 1100000)
            ->assertJsonPath('data.0.value', 11000000);
    }

    public function test_movements_are_listed_newest_first_with_the_staff_member()
    {
        $item = $this->stocked(10);
        $user = $this->staff('Warehouse');
        $this->inventory()->issue($item, 2, $user, 'کار کارگاه');

        $r = $this->getJson("/api/v1/admin/stock-items/{$item->id}/movements", $this->header($user))->assertStatus(200);

        $r->assertJsonPath('data.0.type', 'out')
            ->assertJsonPath('data.0.quantity', -2)
            ->assertJsonPath('data.0.balance_after', 8)
            ->assertJsonPath('data.0.note', 'کار کارگاه')
            ->assertJsonPath('data.0.user.id', $user->id)
            ->assertJsonPath('data.1.type', 'in');
    }

    // --- create and edit --------------------------------------------------------

    public function test_creates_an_mdf_sheet_with_zero_stock()
    {
        $r = $this->postJson('/api/v1/admin/stock-items', $this->mdf(), $this->header('Warehouse'));

        $r->assertStatus(201)
            ->assertJsonPath('data.code', 'MDF-16-WH')
            ->assertJsonPath('data.unit', 'sheet')
            ->assertJsonPath('data.quantity', 0)
            ->assertJsonPath('data.thickness_mm', 16)
            ->assertJsonPath('data.reorder_point', 10);
    }

    public function test_quantity_and_cost_cannot_be_set_through_the_item_form()
    {
        $this->postJson('/api/v1/admin/stock-items', $this->mdf(['quantity' => 99, 'average_cost' => 5]), $this->header('Warehouse'))->assertStatus(201);

        $item = StockItem::firstOrFail();
        $this->assertSame(0.0, $item->quantity);
        $this->assertSame(0, $item->average_cost);
    }

    public function test_invalid_items_are_rejected()
    {
        $headers = $this->header('Warehouse');
        StockItem::factory()->create(['code' => 'HNG-01']);

        $this->postJson('/api/v1/admin/stock-items', $this->mdf(['code' => 'HNG-01']), $headers)->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/admin/stock-items', $this->mdf(['thickness_mm' => null]), $headers)->assertStatus(422)->assertJsonValidationErrors('thickness_mm');
        $this->postJson('/api/v1/admin/stock-items', $this->mdf(['kind' => 'wood']), $headers)->assertStatus(422);
        $this->postJson('/api/v1/admin/stock-items', $this->mdf(['unit' => 'box']), $headers)->assertStatus(422);

        $this->assertSame(1, StockItem::count());
    }

    public function test_edit_deactivates_keeps_history_and_is_audited()
    {
        $item = $this->stocked(5, 0, ['reorder_point' => 10]);
        $user = $this->staff('Warehouse');
        $payload = ['code' => $item->code, 'name' => $item->name, 'kind' => 'hardware', 'unit' => 'piece', 'reorder_point' => 20, 'active' => false];

        $this->putJson("/api/v1/admin/stock-items/{$item->id}", $payload, $this->header($user))
            ->assertStatus(200)
            ->assertJsonPath('data.reorder_point', 20)
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.quantity', 5);

        $this->assertSame(1, StockMovement::where('stock_item_id', $item->id)->count());
        $this->assertCount(0, $this->getJson('/api/v1/admin/stock-items', $this->header($user))->json('data'));

        $activity = Activity::where('subject_type', StockItem::class)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertEquals(10, $activity->properties['old']['reorder_point']);
        $this->assertEquals(20, $activity->properties['attributes']['reorder_point']);
    }

    // --- movements --------------------------------------------------------------

    public function test_records_receipt_issue_and_count()
    {
        $item = $this->stocked(4);
        $headers = $this->header('Warehouse');
        $url = "/api/v1/admin/stock-items/{$item->id}/movements";

        $this->postJson($url, ['type' => 'in', 'quantity' => 10], $headers)
            ->assertStatus(201)->assertJsonPath('data.quantity', 14)->assertJsonPath('movement.quantity', 10);
        $this->postJson($url, ['type' => 'adjust', 'quantity' => 12.5, 'note' => 'شمارش ماهانه'], $headers)
            ->assertStatus(201)->assertJsonPath('data.quantity', 12.5)->assertJsonPath('movement.quantity', -1.5);
        $this->postJson($url, ['type' => 'out', 'quantity' => 2, 'note' => 'کار کارگاه'], $headers)
            ->assertStatus(201)->assertJsonPath('data.quantity', 10.5);
        $this->postJson($url, ['type' => 'adjust', 'quantity' => 0, 'note' => 'انبار خالی'], $headers)
            ->assertStatus(201)->assertJsonPath('data.quantity', 0);
    }

    public function test_falling_to_the_reorder_point_marks_the_item_low()
    {
        $item = $this->stocked(12, 0, ['reorder_point' => 10]);

        $this->postJson("/api/v1/admin/stock-items/{$item->id}/movements", ['type' => 'out', 'quantity' => 2, 'note' => 'کار'], $this->header('Warehouse'))
            ->assertStatus(201)->assertJsonPath('data.is_low', true);
    }

    public function test_invalid_movements_are_rejected_and_change_nothing()
    {
        $item = $this->stocked(3);
        $headers = $this->header('Warehouse');
        $url = "/api/v1/admin/stock-items/{$item->id}/movements";

        $this->postJson($url, ['type' => 'out', 'quantity' => 2], $headers)->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson($url, ['type' => 'adjust', 'quantity' => 2], $headers)->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson($url, ['type' => 'out', 'quantity' => 5, 'note' => 'x'], $headers)->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->postJson($url, ['type' => 'in', 'quantity' => 0], $headers)->assertStatus(422);
        $this->postJson($url, ['type' => 'in', 'quantity' => -1], $headers)->assertStatus(422);
        $this->postJson($url, ['type' => 'in', 'quantity' => 1.2345], $headers)->assertStatus(422);
        $this->postJson($url, ['type' => 'move', 'quantity' => 1], $headers)->assertStatus(422);

        $this->assertSame(3.0, $item->refresh()->quantity);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_unit_cost_needs_costs_view()
    {
        $item = $this->stocked(10, 1000000);
        $url = "/api/v1/admin/stock-items/{$item->id}/movements";

        $this->postJson($url, ['type' => 'in', 'quantity' => 10, 'unit_cost' => 1200000], $this->header('Warehouse'))->assertStatus(403);
        $this->assertSame(10.0, $item->refresh()->quantity);

        $this->postJson($url, ['type' => 'in', 'quantity' => 10, 'unit_cost' => 1200000], $this->header($this->staff('Warehouse', ['costs.view'])))
            ->assertStatus(201)
            ->assertJsonPath('data.average_cost', 1100000)
            ->assertJsonPath('movement.unit_cost', 1200000);
    }

    // --- permissions ------------------------------------------------------------

    public function test_technic_can_view_but_not_change()
    {
        $item = $this->stocked(5);
        $headers = $this->header('Technic');

        $this->getJson('/api/v1/admin/stock-items', $headers)->assertStatus(200);
        $this->getJson("/api/v1/admin/stock-items/{$item->id}", $headers)->assertStatus(200);
        $this->postJson('/api/v1/admin/stock-items', $this->mdf(), $headers)->assertStatus(403);
        $this->putJson("/api/v1/admin/stock-items/{$item->id}", $this->mdf(), $headers)->assertStatus(403);
        $this->postJson("/api/v1/admin/stock-items/{$item->id}/movements", ['type' => 'in', 'quantity' => 1], $headers)->assertStatus(403);
        $this->assertSame(5.0, $item->refresh()->quantity);
    }

    public function test_sales_admin_and_customers_have_no_access()
    {
        $this->getJson('/api/v1/admin/stock-items', $this->header('Sales'))->assertStatus(403);
        $this->getJson('/api/v1/admin/stock-items', $this->header('Admin'))->assertStatus(403);
        $this->getJson('/api/v1/admin/stock-items', $this->header(User::factory()->create()))->assertStatus(403);
    }

    public function test_a_warehouse_user_gets_a_permission_list_and_an_audited_sign_in()
    {
        $user = User::factory()->create(['email' => 'wh@example.test', 'password' => bcrypt('secret-123')]);
        $user->assignRole('Warehouse');

        $token = $this->postJson('/api/v1/email-login', ['email' => 'wh@example.test', 'password' => 'secret-123'])->assertStatus(200)->json('token');
        $permissions = $this->getJson('/api/v1/user', ['Authorization' => "Bearer {$token}"])->assertStatus(200)->json('permissions');

        $this->assertContains('inventory.view', $permissions);
        $this->assertContains('inventory.manage', $permissions);
        $this->assertTrue(Activity::where('event', 'login')->where('causer_id', $user->id)->exists());

        // and the staff screen lists them
        $listed = collect($this->getJson('/api/v1/admin/staff', $this->header('SuperAdmin'))->assertStatus(200)->json('data'))->pluck('id');
        $this->assertTrue($listed->contains($user->id));
    }

    public function test_super_admin_can_give_the_warehouse_role_and_sees_costs()
    {
        $admin = $this->header('SuperAdmin');
        $staff = User::factory()->create();

        $this->patchJson("/api/v1/admin/staff/{$staff->id}", ['role' => 'Warehouse'], $admin)->assertStatus(200);
        $this->assertTrue($staff->fresh()->hasRole('Warehouse'));
        $this->getJson('/api/v1/admin/stock-items', $this->header($staff->fresh()))->assertStatus(200);

        $item = $this->stocked(2, 500000);
        $this->getJson("/api/v1/admin/stock-items/{$item->id}", $admin)->assertJsonPath('data.stock_value', 1000000);
    }

    // --- square metres --------------------------------------------------------------

    public function test_an_item_can_be_counted_in_square_metres()
    {
        $id = $this->postJson('/api/v1/admin/stock-items', ['code' => 'MIRROR', 'name' => 'آینه', 'kind' => 'other', 'unit' => 'square_metre'], $this->header('Warehouse'))
            ->assertStatus(201)
            ->assertJsonPath('data.unit', 'square_metre')
            ->assertJsonPath('data.unit_label', 'متر مربع')
            ->json('data.id');

        $this->postJson("/api/v1/admin/stock-items/{$id}/movements", ['type' => 'in', 'quantity' => 1.255], $this->header('Warehouse'))
            ->assertStatus(201)
            ->assertJsonPath('data.quantity', 1.255);
    }

    public function test_cost_per_square_metre_is_shown_for_sized_sheets_and_m2_items_with_costs_view()
    {
        $sheet = $this->stocked(1, 19423620, $this->mdf());
        $unsized = $this->stocked(1, 1000000, $this->mdf(['code' => 'MDF-X', 'length_mm' => null, 'width_mm' => null]));
        $glass = $this->stocked(2, 4000000, ['code' => 'GLASS', 'kind' => 'other', 'unit' => 'square_metre']);
        $withCosts = $this->header($this->staff('Warehouse', ['costs.view']));

        $this->getJson("/api/v1/admin/stock-items/{$sheet->id}", $withCosts)->assertJsonPath('data.average_cost_per_m2', 2900000);
        $this->getJson("/api/v1/admin/stock-items/{$glass->id}", $withCosts)->assertJsonPath('data.average_cost_per_m2', 4000000);
        $this->getJson("/api/v1/admin/stock-items/{$unsized->id}", $withCosts)->assertJsonMissingPath('data.average_cost_per_m2');
        $this->getJson("/api/v1/admin/stock-items/{$sheet->id}", $this->header('Warehouse'))->assertJsonMissingPath('data.average_cost_per_m2');
    }

    public function test_a_sheet_receipt_can_be_priced_per_square_metre()
    {
        $sheet = StockItem::factory()->create($this->mdf());
        $url = "/api/v1/admin/stock-items/{$sheet->id}/movements";

        $this->postJson($url, ['type' => 'in', 'quantity' => 5, 'unit_cost_per_m2' => 2900000], $this->header('Warehouse'))->assertStatus(403);
        $this->assertSame(0.0, $sheet->refresh()->quantity);

        $this->postJson($url, ['type' => 'in', 'quantity' => 5, 'unit_cost_per_m2' => 2900000], $this->header($this->staff('Warehouse', ['costs.view'])))
            ->assertStatus(201)
            ->assertJsonPath('movement.unit_cost', 19423620)
            ->assertJsonPath('data.average_cost', 19423620)
            ->assertJsonPath('data.average_cost_per_m2', 2900000);
    }

    public function test_a_square_metre_price_is_refused_where_it_cannot_be_converted()
    {
        $manager = $this->header($this->staff('Warehouse', ['costs.view']));
        $hinge = StockItem::factory()->create(['code' => 'HINGE', 'kind' => 'hardware', 'unit' => 'piece']);
        $sheet = StockItem::factory()->create($this->mdf());

        $this->postJson("/api/v1/admin/stock-items/{$hinge->id}/movements", ['type' => 'in', 'quantity' => 4, 'unit_cost_per_m2' => 100000], $manager)
            ->assertStatus(422)->assertJsonValidationErrors('unit_cost_per_m2');
        $this->postJson("/api/v1/admin/stock-items/{$sheet->id}/movements", ['type' => 'in', 'quantity' => 4, 'unit_cost' => 1, 'unit_cost_per_m2' => 100000], $manager)
            ->assertStatus(422);
        $this->postJson("/api/v1/admin/stock-items/{$sheet->id}/movements", ['type' => 'out', 'quantity' => 1, 'note' => 'x', 'unit_cost_per_m2' => 100000], $manager)
            ->assertStatus(422);
        $this->assertSame(0, StockMovement::count());
    }

    // --- cutting data on sheets -------------------------------------------------

    public function test_a_sheet_keeps_its_waste_and_default_edge_band()
    {
        $headers = $this->header('Warehouse');
        $band = StockItem::factory()->create(['code' => 'EB-WH-2', 'kind' => 'edge_band', 'unit' => 'meter']);
        $hinge = StockItem::factory()->create(['code' => 'HNG-01']);

        $this->postJson('/api/v1/admin/stock-items', $this->mdf(), $headers)->assertStatus(201)->assertJsonPath('data.waste_percent', 20);
        $this->postJson('/api/v1/admin/stock-items', $this->mdf(['code' => 'MDF-16-WH2', 'waste_percent' => 25, 'default_edge_band_id' => $band->id]), $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.waste_percent', 25)
            ->assertJsonPath('data.default_edge_band.code', 'EB-WH-2');

        $this->postJson('/api/v1/admin/stock-items', $this->mdf(['code' => 'X1', 'default_edge_band_id' => $hinge->id]), $headers)
            ->assertStatus(422)->assertJsonValidationErrors('default_edge_band_id');
        $this->postJson('/api/v1/admin/stock-items', $this->mdf(['code' => 'X2', 'waste_percent' => 120]), $headers)
            ->assertStatus(422)->assertJsonValidationErrors('waste_percent');
    }
}
