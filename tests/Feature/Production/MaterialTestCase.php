<?php

namespace Tests\Feature\Production;

use App\Enums\OrderStatus;
use App\Models\BomLine;
use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\StockItem;
use App\Models\Variation;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\Inventory\InventoryService;
use App\Services\Production\DesignService;
use App\Services\Production\WorkOrderMaterialService;

abstract class MaterialTestCase extends ProductionTestCase
{
    protected function materials(): WorkOrderMaterialService
    {
        return app(WorkOrderMaterialService::class);
    }

    protected function stock(float $quantity, array $attributes = [], int $cost = 0): StockItem
    {
        $item = StockItem::factory()->create($attributes);
        if ($quantity > 0) {
            app(InventoryService::class)->receive($item, $quantity, $cost, null);
        }

        return $item->refresh();
    }

    /** The product's default design, or the variation's default design, made on first use. */
    protected function design(Product $product, ?Variation $variation = null): ProductDesign
    {
        return app(DesignService::class)->defaultFor($product, $variation?->id, true);
    }

    protected function bom(Product $product, array $lines, ?Variation $variation = null): ProductDesign
    {
        $design = $this->design($product, $variation);
        foreach ($lines as [$stockItem, $quantity]) {
            BomLine::create(['design_id' => $design->id, 'stock_item_id' => $stockItem->id, 'quantity' => $quantity]);
        }

        return $design;
    }

    /** A confirmed order with one line of the given variation (or a custom line), and its work order. */
    protected function workOrderFor(?Variation $variation, int $quantity = 1, ?ProductDesign $design = null): WorkOrder
    {
        $order = Order::factory()->create(['status' => OrderStatus::CONFIRMED->value]);
        Item::create([
            'title' => 'میز تحریر',
            'slug' => 'custom',
            'price' => 5000000,
            'quantity' => $quantity,
            'product_id' => $variation?->product_id,
            'variation_id' => $variation?->id,
            'design_id' => $design?->id,
            'order_id' => $order->id,
        ]);

        return $this->service()->createForOrder($order)[0];
    }

    /** A work order with one hand-made material line. */
    protected function workOrderNeeding(StockItem $item, float $planned): array
    {
        $workOrder = $this->workOrderFor(null);
        $this->materials()->replacePlan($workOrder, [['stock_item_id' => $item->id, 'quantity' => $planned]]);

        return [$workOrder, $workOrder->materials()->firstOrFail()];
    }

    protected function line(WorkOrderMaterial $material): WorkOrderMaterial
    {
        return $material->fresh();
    }
}
