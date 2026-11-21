<?php

namespace App\Services\Costing;

use App\Enums\CostAdderUnit;
use App\Enums\WorkOrderState;
use App\Models\CostAdder;
use App\Models\DesignAdder;
use App\Models\ProductDesign;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAdder;
use App\Services\Production\DesignService;
use App\Services\Production\WorkOrderMaterialService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts cost adders on designs and work orders. A stock-linked adder (rail, magnet) is only a shortcut onto the
 * BOM or the material list, so its cost keeps coming from stock movements; a service adder is kept as its own
 * row, and on a work order it carries the unit cost it had when it was added.
 */
class CostAdderService
{
    public function __construct(private DesignService $designs, private WorkOrderMaterialService $materials)
    {
    }

    // --- designs -----------------------------------------------------------------------------------------------

    /** Stock-linked: adds to the BOM line of that item. Service: sets the design's quantity of it. */
    public function addToDesign(ProductDesign $design, CostAdder $adder, float $quantity): void
    {
        $this->assertActive($adder);
        $quantity = round($quantity, 3);

        if ($adder->isStock()) {
            $this->designs->changingContent($design, function () use ($design, $adder, $quantity) {
                $line = $design->bomLines()->where('stock_item_id', $adder->stock_item_id)->lockForUpdate()->first();
                if ($line) {
                    $line->update(['quantity' => round($line->quantity + $quantity, 3)]);
                } else {
                    $design->bomLines()->create(['stock_item_id' => $adder->stock_item_id, 'quantity' => $quantity]);
                }
            });

            return;
        }

        DesignAdder::updateOrCreate(
            ['design_id' => $design->id, 'cost_adder_id' => $adder->id],
            ['quantity' => $quantity],
        );
        $design->touch();
    }

    public function removeFromDesign(DesignAdder $row): void
    {
        $row->delete();
    }


    // --- work orders -------------------------------------------------------------------------------------------

    /** Copies the design's service adders × the work order's quantity (per-work-order ones are not multiplied). */
    public function copyToWorkOrder(ProductDesign $design, WorkOrder $workOrder): void
    {
        foreach ($design->adders()->with('adder')->get() as $row) {
            $workOrder->adders()->create([
                'cost_adder_id' => $row->cost_adder_id,
                'quantity' => $this->scaled($row->quantity, $row->adder, $workOrder->quantity),
                'unit_cost' => (int) $row->adder->unit_cost,
                'from_design' => true,
            ]);
        }
    }


    /**
     * Stock-linked: adds to the planned material list and returns null. Service: a new row at the adder's current
     * unit cost, or `$unitCost` when a cost manager gives one.
     */
    public function addToWorkOrder(WorkOrder $workOrder, CostAdder $adder, float $quantity, ?User $user, ?int $unitCost = null): ?WorkOrderAdder
    {
        $this->assertActive($adder);
        $quantity = round($quantity, 3);

        if ($adder->isStock()) {
            DB::transaction(function () use ($workOrder, $adder, $quantity) {
                $lines = WorkOrder::lockForUpdate()->findOrFail($workOrder->id)->materials()->get()
                    ->map(fn ($m) => ['stock_item_id' => $m->stock_item_id, 'quantity' => $m->planned_quantity, 'note' => $m->note])
                    ->keyBy('stock_item_id');
                $current = $lines->get($adder->stock_item_id, ['stock_item_id' => $adder->stock_item_id, 'quantity' => 0, 'note' => null]);
                $current['quantity'] = round($current['quantity'] + $quantity, 3);
                $lines->put($adder->stock_item_id, $current);

                $this->materials->replacePlan($workOrder, $lines->values()->all());
            });

            return null;
        }

        return DB::transaction(function () use ($workOrder, $adder, $quantity, $user, $unitCost) {
            $this->assertOpen(WorkOrder::lockForUpdate()->findOrFail($workOrder->id));

            return $workOrder->adders()->create([
                'cost_adder_id' => $adder->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost ?? (int) $adder->unit_cost,
                'created_by' => $user?->id,
            ]);
        });
    }

    /** Quantity changes keep the unit cost; only a cost manager's explicit `$unitCost` changes it. */
    public function updateWorkOrderAdder(WorkOrderAdder $row, ?float $quantity, ?int $unitCost): WorkOrderAdder
    {
        return DB::transaction(function () use ($row, $quantity, $unitCost) {
            $this->assertOpen(WorkOrder::lockForUpdate()->findOrFail($row->work_order_id));

            $row->update(array_filter([
                'quantity' => $quantity !== null ? round($quantity, 3) : null,
                'unit_cost' => $unitCost,
            ], fn ($v) => $v !== null));

            return $row->refresh();
        });
    }

    public function removeWorkOrderAdder(WorkOrderAdder $row): void
    {
        DB::transaction(function () use ($row) {
            $this->assertOpen(WorkOrder::lockForUpdate()->findOrFail($row->work_order_id));
            $row->delete();
        });
    }

    /** Saving a work order as a design keeps its service adders per unit (per-work-order ones as they are). */
    public function saveToDesign(WorkOrder $workOrder, ProductDesign $design): void
    {
        $design->adders()->get()->each->delete();
        $quantity = max(1, (int) $workOrder->quantity);

        $workOrder->adders()->with('adder')->get()->groupBy('cost_adder_id')->each(function ($rows, $adderId) use ($design, $quantity) {
            $adder = $rows->first()->adder;
            $total = $rows->sum('quantity');
            $perUnit = $adder->unit === CostAdderUnit::WORK_ORDER ? round($total, 3) : round($total / $quantity, 3);
            if ($perUnit > 0) {
                $design->adders()->create(['cost_adder_id' => $adderId, 'quantity' => $perUnit]);
            }
        });
    }

    private function scaled(float $quantity, CostAdder $adder, int|float $workOrderQuantity): float
    {
        return $adder->unit === CostAdderUnit::WORK_ORDER ? round($quantity, 3) : round($quantity * $workOrderQuantity, 3);
    }

    private function assertActive(CostAdder $adder): void
    {
        if (!$adder->active) {
            throw ValidationException::withMessages(['cost_adder_id' => ['این افزودنی غیرفعال است.']]);
        }
    }

    private function assertOpen(WorkOrder $workOrder): void
    {
        if (in_array($workOrder->state, [WorkOrderState::DONE, WorkOrderState::CANCELLED], true)) {
            throw ValidationException::withMessages(['adders' => ['افزودنی‌های دستور تولیدِ تمام‌شده یا لغوشده قابل تغییر نیست.']]);
        }
    }
}
