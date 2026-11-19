<?php

namespace Tests\Feature\Production;

use App\Enums\OrderStatus;
use App\Enums\ProductionStage;
use App\Services\Production\DesignResolver;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Variation;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class WorkOrderMaterialServiceTest extends MaterialTestCase
{
    public function test_schema_and_grants()
    {
        $this->assertTrue(Schema::hasColumns('bom_lines', ['design_id', 'stock_item_id', 'quantity', 'note']));
        $this->assertFalse(Schema::hasColumn('bom_lines', 'product_id'));
        $this->assertTrue(Schema::hasColumns('work_order_materials', ['work_order_id', 'stock_item_id', 'planned_quantity', 'reserved_quantity', 'consumed_quantity', 'wasted_quantity']));
        $this->assertTrue(Schema::hasColumn('stock_items', 'reserved'));

        $names = fn (string $role) => Role::findByName($role)->permissions->pluck('name');
        $this->assertTrue($names('Technic')->contains('bom.manage'));
        foreach (['Sales', 'Warehouse', 'Admin'] as $role) {
            $this->assertFalse($names($role)->contains('bom.manage'), $role);
        }

        [$workOrder, $material] = $this->workOrderNeeding($this->stock(1), 1);
        $this->expectException(\Illuminate\Database\QueryException::class);
        WorkOrderMaterial::create(['work_order_id' => $workOrder->id, 'stock_item_id' => $material->stock_item_id, 'planned_quantity' => 1]);
    }

    // --- template ---------------------------------------------------------------

    public function test_bom_resolves_to_the_variation_then_the_product_then_nothing()
    {
        $walnut = Variation::factory()->create();
        $other = Variation::factory()->create(['product_id' => $walnut->product_id]);
        $bare = Variation::factory()->create();
        $white = $this->stock(0);
        $brown = $this->stock(0);
        $this->bom($walnut->product, [[$white, 1.5]]);
        $this->bom($walnut->product, [[$brown, 1.5]], $walnut);

        $resolve = fn ($productId, $variationId) => app(DesignResolver::class)->forLine(null, $productId, $variationId);

        $this->assertSame($brown->id, $resolve($walnut->product_id, $walnut->id)->bomLines[0]->stock_item_id);
        $this->assertSame($white->id, $resolve($other->product_id, $other->id)->bomLines[0]->stock_item_id);
        $this->assertNull($resolve($bare->product_id, $bare->id));
        $this->assertNull($resolve(null, null));
    }

    public function test_work_order_list_is_the_bom_times_its_quantity()
    {
        $variation = Variation::factory()->create();
        $sheet = $this->stock(0);
        $hinge = $this->stock(0);
        $this->bom($variation->product, [[$sheet, 1.5], [$hinge, 4]]);

        $workOrder = $this->workOrderFor($variation, 2);

        $planned = $workOrder->materials()->pluck('planned_quantity', 'stock_item_id');
        $this->assertEquals(3, $planned[$sheet->id]);
        $this->assertEquals(8, $planned[$hinge->id]);

        // creating the order's work orders again must not duplicate the list
        $this->service()->createForOrder($workOrder->order);
        $this->assertSame(2, WorkOrderMaterial::count());
    }

    public function test_custom_line_and_product_without_bom_start_empty()
    {
        $this->assertSame(0, $this->workOrderFor(null)->materials()->count());
        $this->assertSame(0, $this->workOrderFor(Variation::factory()->create())->materials()->count());
    }

    // --- editing the plan -------------------------------------------------------

    public function test_plan_can_be_replaced_until_material_is_held_or_used()
    {
        $sheet = $this->stock(10);
        $hinge = $this->stock(10);
        $workOrder = $this->workOrderFor(null);

        $this->materials()->replacePlan($workOrder, [['stock_item_id' => $sheet->id, 'quantity' => 2], ['stock_item_id' => $hinge->id, 'quantity' => 8, 'note' => 'کلیپسی']]);
        $this->materials()->replacePlan($workOrder, [['stock_item_id' => $sheet->id, 'quantity' => 3]]);

        $this->assertEquals([$sheet->id => 3.0], $workOrder->materials()->pluck('planned_quantity', 'stock_item_id')->all());

        $this->materials()->consume($workOrder->materials()->first(), 1, 0, null);

        foreach ([[], [['stock_item_id' => $sheet->id, 'quantity' => 0.5]]] as $lines) {
            try {
                $this->materials()->replacePlan($workOrder, $lines);
                $this->fail('expected a validation error');
            } catch (ValidationException $e) {
                $this->assertEquals(3, $workOrder->materials()->first()->planned_quantity);
            }
        }
    }

    public function test_plan_of_a_done_or_cancelled_work_order_is_closed()
    {
        [$workOrder] = $this->workOrderNeeding($this->stock(5), 1);
        $this->service()->moveToStage($workOrder, ProductionStage::CNC, null);
        $this->service()->complete($workOrder);

        $this->expectException(ValidationException::class);
        $this->materials()->replacePlan($workOrder, []);
    }

    // --- reservation ------------------------------------------------------------

    public function test_reserve_holds_what_is_needed_and_available()
    {
        $sheet = $this->stock(10);
        [$workOrder, $material] = $this->workOrderNeeding($sheet, 3);

        $this->materials()->reserve($workOrder);
        $this->materials()->reserve($workOrder);

        $this->assertSame(3.0, $this->line($material)->reserved_quantity);
        $sheet->refresh();
        $this->assertSame([10.0, 3.0, 7.0], [$sheet->quantity, $sheet->reserved, $sheet->available]);
    }

    public function test_partial_reservation_and_topping_up_later()
    {
        $hinge = $this->stock(5);
        [$workOrder, $material] = $this->workOrderNeeding($hinge, 8);

        $this->materials()->reserve($workOrder);
        $this->assertSame(5.0, $this->line($material)->reserved_quantity);
        $this->assertSame(3.0, $this->line($material)->unreserved_need);

        app(InventoryService::class)->receive($hinge, 10, null, null);
        $this->materials()->reserve($workOrder);

        $this->assertSame(8.0, $this->line($material)->reserved_quantity);
        $this->assertSame(8.0, $hinge->refresh()->reserved);
    }

    public function test_two_work_orders_never_hold_more_than_there_is()
    {
        $sheet = $this->stock(4);
        [$first, $a] = $this->workOrderNeeding($sheet, 3);
        [$second, $b] = $this->workOrderNeeding($sheet, 3);

        $this->materials()->reserve($first);
        $this->materials()->reserve($second);

        $this->assertSame([3.0, 1.0], [$this->line($a)->reserved_quantity, $this->line($b)->reserved_quantity]);
        $this->assertSame(4.0, $sheet->refresh()->reserved);
    }

    public function test_reserved_stock_cannot_leave_by_a_manual_issue_or_a_count()
    {
        $sheet = $this->stock(10);
        [$workOrder] = $this->workOrderNeeding($sheet, 8);
        $this->materials()->reserve($workOrder);
        $inventory = app(InventoryService::class);

        foreach ([fn () => $inventory->issue($sheet, 5, null, 'x'), fn () => $inventory->adjust($sheet, 7, null, 'x')] as $attempt) {
            try {
                $attempt();
                $this->fail('expected a validation error');
            } catch (ValidationException $e) {
                $this->assertSame(10.0, $sheet->refresh()->quantity);
            }
        }

        $inventory->issue($sheet, 2, null, 'آزاد');
        $this->assertSame(8.0, $sheet->refresh()->quantity);
    }

    // --- consumption ------------------------------------------------------------

    public function test_consuming_issues_stock_and_gives_back_the_hold()
    {
        $sheet = $this->stock(10);
        [$workOrder, $material] = $this->workOrderNeeding($sheet, 3);
        $this->materials()->reserve($workOrder);
        $user = $this->staff('Technic');

        $this->materials()->consume($material, 2, 0.5, $user);

        $line = $this->line($material);
        $this->assertSame([2.0, 0.5, 0.5], [$line->consumed_quantity, $line->wasted_quantity, $line->reserved_quantity]);
        $sheet->refresh();
        $this->assertSame([7.5, 0.5], [$sheet->quantity, $sheet->reserved]);

        $movements = StockMovement::where('reference_type', (new WorkOrder)->getMorphClass())->where('reference_id', $workOrder->id)->orderBy('id')->get();
        $this->assertSame(['out', 'waste'], $movements->pluck('type')->all());
        $this->assertSame([-2.0, -0.5], $movements->pluck('quantity')->all());
        $this->assertSame($user->id, $movements[0]->user_id);
    }

    public function test_consumption_may_exceed_the_plan_and_needs_no_reservation()
    {
        $sheet = $this->stock(8);
        [, $material] = $this->workOrderNeeding($sheet, 3);

        $this->materials()->consume($material, 3, 0, null);
        $this->materials()->consume($material, 1, 0, null);

        $this->assertSame(4.0, $this->line($material)->consumed_quantity);
        $this->assertSame([4.0, 0.0], [$sheet->refresh()->quantity, $sheet->reserved]);
    }

    public function test_stock_held_for_another_job_cannot_be_consumed()
    {
        $sheet = $this->stock(4);
        [$other] = $this->workOrderNeeding($sheet, 4);
        $this->materials()->reserve($other);
        [, $material] = $this->workOrderNeeding($sheet, 1);

        try {
            $this->materials()->consume($material, 1, 0, null);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame([4.0, 4.0], [$sheet->refresh()->quantity, $sheet->reserved]);
            $this->assertSame(0.0, $this->line($material)->consumed_quantity);
        }
    }

    public function test_cancelled_work_order_cannot_consume()
    {
        [$workOrder, $material] = $this->workOrderNeeding($this->stock(5), 1);
        $this->service()->cancelForOrder($workOrder->order);

        $this->expectException(ValidationException::class);
        $this->materials()->consume($material, 1, 0, null);
    }

    // --- release ----------------------------------------------------------------

    public function test_completing_releases_what_is_left()
    {
        $sheet = $this->stock(10);
        [$workOrder, $material] = $this->workOrderNeeding($sheet, 3);
        $this->materials()->reserve($workOrder);
        $this->materials()->consume($material, 2.5, 0, null);
        $this->service()->moveToStage($workOrder, ProductionStage::PACKAGING, null);

        $this->service()->complete($workOrder);

        $this->assertSame(0.0, $this->line($material)->reserved_quantity);
        $this->assertSame([7.5, 0.0, 7.5], [$sheet->refresh()->quantity, $sheet->reserved, $sheet->available]);
    }

    public function test_cancelling_the_order_releases_but_keeps_what_was_used()
    {
        $sheet = $this->stock(10);
        [$workOrder, $material] = $this->workOrderNeeding($sheet, 4);
        $this->materials()->reserve($workOrder);
        $this->materials()->consume($material, 1, 0, null);

        $workOrder->order->update(['status' => OrderStatus::CANCELED->value]);
        $this->service()->cancelForOrder($workOrder->order);

        $line = $this->line($material);
        $this->assertSame([0.0, 1.0], [$line->reserved_quantity, $line->consumed_quantity]);
        $this->assertSame([9.0, 0.0], [$sheet->refresh()->quantity, $sheet->reserved]);
    }

    public function test_cached_reserved_matches_the_lines_and_can_be_recounted()
    {
        $sheet = $this->stock(20);
        [$first, $a] = $this->workOrderNeeding($sheet, 5);
        [$second, $b] = $this->workOrderNeeding($sheet, 6);
        $this->materials()->reserve($first);
        $this->materials()->reserve($second);
        $this->materials()->consume($a, 2, 1, null);
        $this->materials()->release($second);
        $this->materials()->reserve($second);

        $sum = fn () => (float) WorkOrderMaterial::where('stock_item_id', $sheet->id)->sum('reserved_quantity');
        $this->assertEqualsWithDelta($sum(), $sheet->refresh()->reserved, 0.0001);
        $this->assertSame(8.0, $sheet->reserved);

        StockItem::where('id', $sheet->id)->update(['reserved' => 99]);
        $this->artisan('inventory:recount-reserved')->assertSuccessful();
        $this->assertSame(8.0, $sheet->refresh()->reserved);
    }

    // --- shortages --------------------------------------------------------------

    public function test_shortages_list_what_open_work_orders_need_beyond_stock()
    {
        $hinge = $this->stock(6, ['name' => 'لولا']);
        $sheet = $this->stock(10, ['name' => 'ورق']);
        [$first] = $this->workOrderNeeding($hinge, 5);
        [$second] = $this->workOrderNeeding($hinge, 4);
        $this->workOrderNeeding($sheet, 4);

        $rows = $this->materials()->shortages();

        $this->assertCount(1, $rows);
        $this->assertSame($hinge->id, $rows[0]['stock_item']->id);
        $this->assertSame([9.0, 6.0, 3.0], [$rows[0]['need'], $rows[0]['available'], $rows[0]['missing']]);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $rows[0]['work_orders']->pluck('id')->all());
    }

    public function test_done_and_cancelled_work_orders_do_not_count_as_shortage()
    {
        $hinge = $this->stock(1);
        [$workOrder] = $this->workOrderNeeding($hinge, 5);
        $this->assertCount(1, $this->materials()->shortages());

        $this->service()->cancelForOrder($workOrder->order);

        $this->assertCount(0, $this->materials()->shortages());
    }
}
