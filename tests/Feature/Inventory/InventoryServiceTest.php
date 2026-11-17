<?php

namespace Tests\Feature\Inventory;

use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class InventoryServiceTest extends InventoryTestCase
{
    public function test_schema_and_unique_code()
    {
        $this->assertTrue(Schema::hasColumns('stock_items', [
            'code', 'name', 'kind', 'unit', 'thickness_mm', 'color', 'length_mm', 'width_mm', 'sheet_type',
            'quantity', 'reorder_point', 'average_cost', 'active', 'note',
        ]));
        $this->assertTrue(Schema::hasColumns('stock_movements', [
            'stock_item_id', 'type', 'quantity', 'unit_cost', 'balance_after', 'note', 'reference_type', 'reference_id', 'user_id', 'created_at',
        ]));

        StockItem::factory()->create(['code' => 'HNG-01']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        StockItem::factory()->create(['code' => 'HNG-01']);
    }

    public function test_default_grants()
    {
        $names = fn (string $role) => Role::findByName($role)->permissions->pluck('name');

        $this->assertTrue($names('Warehouse')->contains('inventory.view'));
        $this->assertTrue($names('Warehouse')->contains('inventory.manage'));
        $this->assertTrue($names('Technic')->contains('inventory.view'));
        $this->assertFalse($names('Technic')->contains('inventory.manage'));
        foreach (['Sales', 'Admin', 'Technic', 'Warehouse'] as $role) {
            $this->assertFalse($names($role)->contains('costs.view'), "{$role} must not see costs");
        }
        $this->assertFalse($names('Sales')->contains('inventory.view'));
    }

    public function test_low_scope()
    {
        $make = fn (float $quantity, ?float $point, bool $active = true) => tap(
            StockItem::factory()->create(['reorder_point' => $point, 'active' => $active]),
            fn ($item) => $quantity > 0 ? $this->inventory()->receive($item, $quantity, null, null) : null
        );

        $at = $make(10, 10);
        $below = $make(4, 10);
        $above = $make(12, 10);
        $noPoint = $make(0, null);
        $inactive = $make(1, 10, false);

        $low = StockItem::query()->low()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$at->id, $below->id], $low);
        $this->assertTrue($below->refresh()->is_low);
        $this->assertFalse($above->refresh()->is_low);
        $this->assertFalse($noPoint->refresh()->is_low);
        $this->assertFalse($inactive->refresh()->is_low);
    }

    public function test_receipt_issue_and_count_keep_the_ledger_and_the_quantity_in_step()
    {
        $item = $this->stocked(4);
        $user = $this->staff('Warehouse');

        $in = $this->inventory()->receive($item, 10, null, $user, 'بار جدید');
        $this->assertSame(14.0, $item->quantity);
        $this->assertSame(10.0, $in->quantity);
        $this->assertSame(14.0, $in->balance_after);
        $this->assertSame($user->id, $in->user_id);

        $count = $this->inventory()->adjust($item, 12.5, $user, 'شمارش ماهانه');
        $this->assertSame(12.5, $item->quantity);
        $this->assertSame(-1.5, $count->quantity);
        $this->assertSame('adjust', $count->type);

        $out = $this->inventory()->issue($item, 2.25, $user, 'کار کارگاه');
        $this->assertSame(-2.25, $out->quantity);

        $item->refresh();
        $this->assertSame(10.25, $item->quantity);
        $this->assertEqualsWithDelta($item->quantity, (float) StockMovement::where('stock_item_id', $item->id)->sum('quantity'), 0.0001);
    }

    public function test_issue_above_on_hand_is_rejected_and_writes_nothing()
    {
        $item = $this->stocked(3);
        $before = StockMovement::count();

        try {
            $this->inventory()->issue($item, 5, null, 'x');
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame(3.0, $item->refresh()->quantity);
            $this->assertSame($before, StockMovement::count());
        }
    }

    public function test_count_equal_to_stock_is_rejected()
    {
        $item = $this->stocked(3);

        $this->expectException(ValidationException::class);
        $this->inventory()->adjust($item, 3, null, 'x');
    }

    public function test_two_receipts_through_stale_copies_are_both_counted()
    {
        $item = StockItem::factory()->create();
        $first = StockItem::find($item->id);
        $second = StockItem::find($item->id);

        $this->inventory()->receive($first, 5, null, null);
        // $second still thinks the quantity is 0; the service must read the row again under the lock.
        $this->inventory()->receive($second, 5, null, null);

        $this->assertSame(10.0, $item->refresh()->quantity);
    }

    public function test_moving_average_follows_costed_receipts()
    {
        $item = $this->stocked(10, 1000000);
        $this->assertSame(1000000, $item->average_cost);

        $movement = $this->inventory()->receive($item, 10, 1200000, null);

        $this->assertSame(1100000, $item->average_cost);
        $this->assertSame(1200000, $movement->unit_cost);
    }

    public function test_receipt_without_cost_and_count_leave_the_average_alone()
    {
        $item = $this->stocked(10, 1000000);

        $movement = $this->inventory()->receive($item, 5, null, null);
        $this->inventory()->adjust($item, 12, null, 'شمارش');

        $this->assertSame(1000000, $item->refresh()->average_cost);
        $this->assertSame(1000000, $movement->unit_cost);
    }

    public function test_an_issue_keeps_its_cost_after_a_later_price_rise()
    {
        $item = $this->stocked(10, 1100000);

        $issue = $this->inventory()->issue($item, 2, null, 'کار');
        $this->inventory()->receive($item, 8, 1500000, null);

        $this->assertSame(1300000, $item->refresh()->average_cost);
        $this->assertSame(1100000, $issue->refresh()->unit_cost);
    }
}
