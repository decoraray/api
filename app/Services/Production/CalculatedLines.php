<?php

namespace App\Services\Production;

use App\Enums\StockItemKind;
use App\Models\ProductDesign;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the sheet and edge band lines of a design's BOM, or a work order's material list, in step with its parts.
 *
 * Each line stores `calculated_quantity`, the share of its quantity that came from the parts. Recalculating swaps
 * the old share for the new one, so what was added by hand or by a stock-linked adder on the same item stays.
 * A line left with nothing is removed. Call inside the transaction that saved the parts.
 */
class CalculatedLines
{
    /** Kinds whose lines follow from the parts when there are parts. */
    public const KINDS = [StockItemKind::MDF_SHEET, StockItemKind::EDGE_BAND];

    public function __construct(private CuttingListService $cutting)
    {
    }

    /** `$hadParts`: whether the design had parts before this save (if not, its sheet lines were typed by hand). */
    public function applyToDesign(ProductDesign $design, bool $hadParts = true): void
    {
        $lines = $this->cutting->calculatedLines($design->parts()->get());
        $this->apply($design->bomLines(), 'quantity', $lines, $hadParts, false);
    }

    /** Refuses with 422 when a line would fall below what is already reserved, consumed or wasted. */
    public function applyToWorkOrder(WorkOrder $workOrder, bool $hadParts = true): void
    {
        $lines = $this->cutting->calculatedLines($workOrder->parts()->get(), max(1, (int) $workOrder->quantity));
        $this->apply($workOrder->materials(), 'planned_quantity', $lines, $hadParts, true);
    }

    /** True for an item whose lines are calculated on an owner with parts. */
    public static function isCalculatedKind($kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    private function apply(HasMany $relation, string $column, array $lines, bool $hadParts, bool $guard): void
    {
        $rows = $relation->with('stockItem')->lockForUpdate()->get()->keyBy('stock_item_id');

        foreach ($rows as $stockItemId => $row) {
            $calculated = $row->calculated_quantity;
            // Sheet lines typed while the owner had no parts give way to the calculated ones.
            if ($calculated === null && !$hadParts && self::isCalculatedKind($row->stockItem?->kind)) {
                $calculated = (float) $row->{$column};
            }
            if ($calculated === null && !isset($lines[$stockItemId])) {
                continue;
            }

            $new = $lines[$stockItemId]['quantity'] ?? null;
            $total = round((float) $row->{$column} - (float) $calculated + (float) $new, 3);
            if ($guard) {
                $this->assertCovers($row, $total);
            }

            if ($total <= 0) {
                $row->delete();
            } else {
                $row->update([$column => $total, 'calculated_quantity' => $new]);
            }
        }

        foreach ($lines as $stockItemId => $line) {
            if ($rows->has($stockItemId) || $line['quantity'] <= 0) {
                continue;
            }
            $relation->create(['stock_item_id' => $stockItemId, $column => $line['quantity'], 'calculated_quantity' => $line['quantity']]);
        }
    }

    /** A work order line may not plan less than it already holds or used. */
    private function assertCovers($row, float $total): void
    {
        $taken = round((float) $row->reserved_quantity + (float) $row->consumed_quantity + (float) $row->wasted_quantity, 3);
        if ($taken > 0 && $total < $taken) {
            $code = $row->stockItem?->code ?? $row->stock_item_id;
            throw ValidationException::withMessages(['parts' => [
                "با این قطعات، «{$code}» کمتر از مقداری می‌شود که برای این دستور تولید رزرو یا مصرف شده است. ابتدا رزرو را آزاد کنید یا قطعات را اصلاح کنید.",
            ]]);
        }
    }
}
