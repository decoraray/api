<?php

namespace App\Services\Production;

use App\Enums\WorkOrderState;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderMaterial;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A work order's materials: the plan, what is held for it, and what it used.
 * Every method locks the stock rows it touches in id order, so two work orders cannot oversell an item.
 */
class WorkOrderMaterialService
{
    public function __construct(private InventoryService $inventory)
    {
    }

    /**
     * Replaces the planned list. The work order is then marked customised.
     *
     * `$byHand`: staff typed the list. On a work order with parts, sheets and edge band follow the parts, so they may
     * not be listed and their lines are kept.
     *
     * @param array<int, array{stock_item_id: int, quantity: float|string, note?: ?string}> $lines
     */
    public function replacePlan(WorkOrder $workOrder, array $lines, bool $byHand = false): void
    {
        DB::transaction(function () use ($workOrder, $lines, $byHand) {
            $workOrder = WorkOrder::lockForUpdate()->findOrFail($workOrder->id);
            $this->assertOpen($workOrder, 'materials');

            $calculated = $byHand && $workOrder->parts()->exists();
            if ($calculated) {
                DesignService::assertNoCalculatedItems($lines);
            }

            $existing = $workOrder->materials()->with('stockItem')->lockForUpdate()->get()->keyBy('stock_item_id');
            $wanted = collect($lines)->keyBy('stock_item_id');

            foreach ($existing as $stockItemId => $material) {
                if ($wanted->has($stockItemId) || ($calculated && CalculatedLines::isCalculatedKind($material->stockItem?->kind))) {
                    continue;
                }
                if ($material->reserved_quantity > 0 || $material->consumed_quantity > 0 || $material->wasted_quantity > 0) {
                    throw ValidationException::withMessages(['materials' => ['ماده‌ای که رزرو یا مصرف شده را نمی‌توان حذف کرد. ابتدا رزرو را آزاد کنید.']]);
                }
                $material->delete();
            }

            foreach ($wanted as $stockItemId => $line) {
                $planned = round((float) $line['quantity'], 3);
                $material = $existing->get($stockItemId);

                if ($material && $planned < $material->consumed_quantity) {
                    throw ValidationException::withMessages(['materials' => ['مقدار برنامه نمی‌تواند کمتر از مقدار مصرف‌شده باشد.']]);
                }

                $workOrder->materials()->updateOrCreate(
                    ['stock_item_id' => $stockItemId],
                    ['planned_quantity' => $planned, 'note' => $line['note'] ?? null],
                );
            }

            $workOrder->forceFill(['customized' => true])->save();
        });
    }

    /** Holds what the work order still needs, as far as stock allows. */
    public function reserve(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder) {
            $workOrder = WorkOrder::lockForUpdate()->findOrFail($workOrder->id);
            $this->assertOpen($workOrder, 'materials');

            foreach ($workOrder->materials()->orderBy('stock_item_id')->lockForUpdate()->get() as $material) {
                $need = $material->unreserved_need;
                if ($need <= 0) {
                    continue;
                }

                $item = StockItem::lockForUpdate()->findOrFail($material->stock_item_id);
                $take = round(min($need, max(0.0, $item->available)), 3);
                if ($take <= 0) {
                    continue;
                }

                $material->update(['reserved_quantity' => round($material->reserved_quantity + $take, 3)]);
                $this->setReserved($item, $item->reserved + $take);
            }
        });
    }

    /** Frees everything still held for the work order. What was consumed stays consumed. */
    public function release(WorkOrder $workOrder): void
    {
        DB::transaction(function () use ($workOrder) {
            $held = $workOrder->materials()->where('reserved_quantity', '>', 0)->orderBy('stock_item_id')->lockForUpdate()->get();

            foreach ($held as $material) {
                $item = StockItem::lockForUpdate()->findOrFail($material->stock_item_id);
                $this->setReserved($item, $item->reserved - $material->reserved_quantity);
                $material->update(['reserved_quantity' => 0]);
            }
        });
    }

    /** Material left stock for this job: issued from the ledger, taken off the job's reservation. */
    public function consume(WorkOrderMaterial $material, float $used, float $wasted, ?User $user): WorkOrderMaterial
    {
        return DB::transaction(function () use ($material, $used, $wasted, $user) {
            $workOrder = WorkOrder::lockForUpdate()->findOrFail($material->work_order_id);
            if ($workOrder->state === WorkOrderState::CANCELLED) {
                throw ValidationException::withMessages(['quantity' => ['این دستور تولید لغو شده است.']]);
            }

            $item = StockItem::lockForUpdate()->findOrFail($material->stock_item_id);
            $material = WorkOrderMaterial::lockForUpdate()->findOrFail($material->id);

            // Give back this line's hold first; the ledger then refuses anything that would eat other jobs' holds.
            $freed = round(min($material->reserved_quantity, $used + $wasted), 3);
            if ($freed > 0) {
                $this->setReserved($item, $item->reserved - $freed);
            }

            $note = 'دستور تولید ' . $workOrder->number;
            if ($used > 0) {
                $this->inventory->issue($item, $used, $user, $note, $workOrder);
            }
            if ($wasted > 0) {
                $this->inventory->issue($item, $wasted, $user, $note, $workOrder, StockMovement::TYPE_WASTE);
            }

            $material->update([
                'reserved_quantity' => round($material->reserved_quantity - $freed, 3),
                'consumed_quantity' => round($material->consumed_quantity + $used, 3),
                'wasted_quantity' => round($material->wasted_quantity + $wasted, 3),
            ]);

            return $material;
        });
    }

    /**
     * Materials that open work orders still need and stock cannot cover.
     *
     * @return Collection<int, array{stock_item: StockItem, need: float, available: float, missing: float, work_orders: Collection}>
     */
    public function shortages(): Collection
    {
        $lines = WorkOrderMaterial::query()
            ->with(['stockItem', 'workOrder:id,number,title,state'])
            ->whereHas('workOrder', fn ($q) => $q->whereIn('state', [WorkOrderState::WAITING->value, WorkOrderState::IN_PROGRESS->value]))
            ->get()
            ->filter(fn (WorkOrderMaterial $line) => $line->unreserved_need > 0);

        return $lines->groupBy('stock_item_id')->map(function (Collection $group) {
            $item = $group->first()->stockItem;
            $need = round($group->sum(fn ($line) => $line->unreserved_need), 3);
            $available = max(0.0, $item->available);

            return [
                'stock_item' => $item,
                'need' => $need,
                'available' => $available,
                'missing' => round($need - $available, 3),
                'work_orders' => $group->map(fn ($line) => $line->workOrder)->unique('id')->values(),
            ];
        })->filter(fn (array $row) => $row['missing'] > 0)->sortBy(fn (array $row) => $row['stock_item']->name)->values();
    }

    /** Rebuilds the cached `stock_items.reserved` from the material lines. Returns how many items were wrong. */
    public function recountReserved(): int
    {
        return DB::transaction(function () {
            $sums = WorkOrderMaterial::query()->selectRaw('stock_item_id, SUM(reserved_quantity) as total')->groupBy('stock_item_id')->pluck('total', 'stock_item_id');
            $fixed = 0;

            foreach (StockItem::lockForUpdate()->get() as $item) {
                $actual = round((float) ($sums[$item->id] ?? 0), 3);
                if (round($item->reserved, 3) !== $actual) {
                    $this->setReserved($item, $actual);
                    $fixed++;
                }
            }

            return $fixed;
        });
    }

    private function setReserved(StockItem $item, float $reserved): void
    {
        $item->forceFill(['reserved' => max(0.0, round($reserved, 3))])->saveQuietly();
    }

    private function assertOpen(WorkOrder $workOrder, string $field): void
    {
        if (in_array($workOrder->state, [WorkOrderState::DONE, WorkOrderState::CANCELLED], true)) {
            throw ValidationException::withMessages([$field => ['مواد دستور تولیدِ تمام‌شده یا لغوشده قابل تغییر نیست.']]);
        }
    }
}
