<?php

namespace Tests\Feature\Purchasing;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Purchasing\PurchasingService;
use Tests\Feature\Inventory\InventoryTestCase;

abstract class PurchasingTestCase extends InventoryTestCase
{
    protected function purchasing(): PurchasingService
    {
        return app(PurchasingService::class);
    }

    protected function supplier(array $attributes = []): Supplier
    {
        return Supplier::factory()->create($attributes);
    }

    /** A draft for the given lines: [[StockItem, quantity, unit price, ?PurchaseRequest], ...]. */
    protected function draft(array $lines, ?Supplier $supplier = null): PurchaseOrder
    {
        return $this->purchasing()->saveDraft(null, [
            'supplier_id' => ($supplier ?? $this->supplier())->id,
            'lines' => array_map(fn ($l) => [
                'stock_item_id' => $l[0]->id,
                'quantity' => $l[1],
                'unit_price' => $l[2],
                'purchase_request_id' => isset($l[3]) ? $l[3]->id : null,
            ], $lines),
        ], null);
    }

    protected function placed(array $lines, ?Supplier $supplier = null): PurchaseOrder
    {
        return $this->purchasing()->place($this->draft($lines, $supplier));
    }

    protected function request(StockItem $item, float $quantity, ?User $by = null): PurchaseRequest
    {
        return PurchaseRequest::create(['stock_item_id' => $item->id, 'quantity' => $quantity, 'requested_by' => ($by ?? $this->staff('Warehouse'))->id]);
    }

    /** Receives on every line of the order: [quantity per line in line order]. */
    protected function receive(PurchaseOrder $order, array $quantities, ?User $by = null)
    {
        $lines = $order->lines()->get()->values();

        return $this->purchasing()->receive($order, array_map(
            fn ($i) => ['purchase_order_line_id' => $lines[$i]->id, 'quantity' => $quantities[$i]],
            array_keys($quantities)
        ), $by);
    }
}
