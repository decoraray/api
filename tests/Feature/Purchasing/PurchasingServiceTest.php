<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseOrderState;
use App\Enums\PurchaseRequestState;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class PurchasingServiceTest extends PurchasingTestCase
{
    public function test_schema_and_default_grants()
    {
        foreach (['suppliers', 'purchase_requests', 'purchase_orders', 'purchase_order_lines', 'goods_receipts', 'goods_receipt_lines'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumns('purchase_order_lines', ['quantity', 'unit_price', 'received_quantity']));

        $names = fn (string $role) => Role::findByName($role)->permissions->pluck('name')->sort()->values()->all();
        $this->assertSame(['costs.view', 'inventory.view', 'purchasing.manage', 'purchasing.receive', 'purchasing.request', 'purchasing.view'], $names('Purchasing'));
        $warehouse = $names('Warehouse');
        $this->assertContains('purchasing.request', $warehouse);
        $this->assertContains('purchasing.receive', $warehouse);
        $technic = $names('Technic');
        $this->assertContains('purchasing.request', $technic);
        $this->assertNotContains('purchasing.receive', $technic);
        foreach ([$warehouse, $technic] as $perms) {
            foreach (['purchasing.view', 'purchasing.manage', 'costs.view'] as $denied) {
                $this->assertNotContains($denied, $perms);
            }
        }
    }

    public function test_draft_gets_a_number_and_changes_no_stock()
    {
        $sheet = $this->stocked(0);

        $order = $this->draft([[$sheet, 20, 1200000]]);

        $this->assertSame('PO-' . $order->id, $order->number);
        $this->assertSame(PurchaseOrderState::DRAFT, $order->state);
        $this->assertSame(24000000, $order->load('lines')->total);
        $this->assertSame(0.0, $sheet->refresh()->quantity);
    }

    public function test_inactive_supplier_is_refused()
    {
        $this->expectException(ValidationException::class);
        $this->draft([[$this->stocked(0), 1, 1]], $this->supplier(['active' => false]));
    }

    public function test_place_orders_the_linked_request_and_cancel_reopens_it()
    {
        $sheet = $this->stocked(0);
        $request = $this->request($sheet, 20);
        $order = $this->draft([[$sheet, 20, 1200000, $request]]);
        $this->assertSame(PurchaseRequestState::OPEN, $request->fresh()->state);

        $this->purchasing()->place($order);
        $this->assertSame(PurchaseRequestState::ORDERED, $request->fresh()->state);

        $this->purchasing()->cancel($order);
        $request->refresh();
        $this->assertSame(PurchaseOrderState::CANCELLED, $order->fresh()->state);
        $this->assertSame(PurchaseRequestState::OPEN, $request->state);
        $this->assertNull($request->purchase_order_line_id);
    }

    public function test_a_request_cannot_be_on_two_orders_or_on_the_wrong_item()
    {
        $sheet = $this->stocked(0);
        $hinge = $this->stocked(0);
        $request = $this->request($sheet, 20);
        $this->draft([[$sheet, 20, 1, $request]]);

        foreach ([[[$sheet, 20, 1, $request]], [[$hinge, 20, 1, $this->request($sheet, 5)]]] as $lines) {
            try {
                $this->draft($lines);
                $this->fail('expected a validation error');
            } catch (ValidationException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_only_drafts_are_edited_or_placed()
    {
        $sheet = $this->stocked(0);
        $order = $this->placed([[$sheet, 20, 1200000]]);

        foreach ([
            fn () => $this->purchasing()->saveDraft($order, ['supplier_id' => $order->supplier_id, 'lines' => [['stock_item_id' => $sheet->id, 'quantity' => 20, 'unit_price' => 1]]], null),
            fn () => $this->purchasing()->place($order),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected a validation error');
            } catch (ValidationException $e) {
                $this->assertSame(1200000, $order->lines()->first()->unit_price);
            }
        }
    }

    public function test_partial_then_full_receipt_brings_stock_in_at_the_order_price()
    {
        $sheet = $this->stocked(10, 1000000);
        $order = $this->placed([[$sheet, 20, 1200000]]);
        $user = $this->staff('Warehouse');

        $receipt = $this->receive($order, [12], $user);
        $this->assertSame(PurchaseOrderState::PARTIALLY_RECEIVED, $order->fresh()->state);
        $this->assertSame(22.0, $sheet->refresh()->quantity);

        $this->receive($order, [8], $user);
        $this->assertSame(PurchaseOrderState::RECEIVED, $order->fresh()->state);
        $this->assertSame(30.0, $sheet->refresh()->quantity);
        // (10 × 1,000,000 + 20 × 1,200,000) / 30
        $this->assertSame(1133333, $sheet->average_cost);

        $movement = StockMovement::where('reference_type', (new GoodsReceipt)->getMorphClass())->where('reference_id', $receipt->id)->firstOrFail();
        $this->assertSame([12.0, 1200000, $user->id], [$movement->quantity, $movement->unit_cost, $movement->user_id]);
        $this->assertSame($order->number, $movement->note);
    }

    public function test_average_cost_follows_the_order_price()
    {
        $sheet = $this->stocked(10, 1000000);
        $order = $this->placed([[$sheet, 10, 1200000]]);

        $this->receive($order, [10]);

        $this->assertSame(1100000, $sheet->refresh()->average_cost);
    }

    public function test_over_receipt_is_refused_and_adds_nothing()
    {
        $sheet = $this->stocked(0);
        $order = $this->placed([[$sheet, 20, 1]]);
        $this->receive($order, [12]);

        try {
            $this->receive($order, [10]);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame(12.0, $sheet->refresh()->quantity);
            $this->assertSame(1, GoodsReceipt::count());
        }
    }

    public function test_no_receipt_on_draft_cancelled_or_closed_orders()
    {
        $sheet = $this->stocked(0);
        $draft = $this->draft([[$sheet, 5, 1]]);
        $cancelled = $this->purchasing()->cancel($this->placed([[$sheet, 5, 1]]));
        $closed = $this->placed([[$sheet, 5, 1]]);
        $this->receive($closed, [2]);
        $this->purchasing()->close($closed);

        foreach ([$draft, $cancelled, $closed] as $order) {
            try {
                $this->receive($order->fresh(), [1]);
                $this->fail('expected a validation error for ' . $order->state->value);
            } catch (ValidationException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(2.0, $sheet->refresh()->quantity);
    }

    public function test_a_received_order_cannot_be_cancelled_but_can_be_closed_short()
    {
        $sheet = $this->stocked(0);
        $request = $this->request($sheet, 20);
        $order = $this->purchasing()->place($this->draft([[$sheet, 20, 1, $request]]));
        $this->receive($order, [12]);

        try {
            $this->purchasing()->cancel($order);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertSame(PurchaseOrderState::PARTIALLY_RECEIVED, $order->fresh()->state);
        }

        $this->purchasing()->close($order);

        $this->assertSame(PurchaseOrderState::CLOSED, $order->fresh()->state);
        $this->assertSame(12.0, $sheet->refresh()->quantity);
        $this->assertSame(PurchaseRequestState::OPEN, $request->fresh()->state);
    }

    public function test_evaluation_and_supplier_average()
    {
        $supplier = $this->supplier();
        $sheet = $this->stocked(0);
        $first = $this->placed([[$sheet, 1, 1]], $supplier);
        $this->receive($first, [1]);
        $second = $this->placed([[$sheet, 2, 1]], $supplier);
        $this->receive($second, [1]);
        $this->purchasing()->close($second);
        $draft = $this->draft([[$sheet, 1, 1]], $supplier);

        $this->purchasing()->evaluate($first->fresh(), 5, null);
        $this->purchasing()->evaluate($second->fresh(), 3, 'دیر رسید');

        $row = \App\Models\Supplier::withAvg('purchaseOrders as score_average', 'score')->find($supplier->id);
        $this->assertEquals(4, $row->score_average);

        $this->expectException(ValidationException::class);
        $this->purchasing()->evaluate($draft, 4, null);
    }
}
