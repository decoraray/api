<?php

namespace App\Support\Production;

use App\Models\BomLine;
use App\Models\ProductDesign;
use App\Models\StockItem;

/** Shared JSON shapes for production responses. None of them carries a cost, for any role. */
class Present
{
    public static function stockItem(StockItem $item): array
    {
        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'unit' => $item->unit->value,
            'unit_label' => $item->unit->label(),
        ];
    }

    public static function bomLine(BomLine $line, float $multiplier = 1): array
    {
        return [
            'id' => $line->id,
            'stock_item' => self::stockItem($line->stockItem),
            'quantity' => round($line->quantity * $multiplier, 3),
            'note' => $line->note,
        ];
    }

    /** A design as listed: no parts or lines, just enough to choose one. */
    public static function design(ProductDesign $design): array
    {
        return [
            'id' => $design->id,
            'product_id' => $design->product_id,
            'name' => $design->name,
            'dimensions' => $design->dimensions,
            'note' => $design->note,
            'is_default' => $design->is_default,
            'variation_id' => $design->variation_id,
            'active' => $design->active,
            'parts_count' => $design->parts_count ?? $design->parts()->count(),
            'bom_count' => $design->bom_lines_count ?? $design->bomLines()->count(),
            'updated_at' => $design->updated_at?->toIso8601String(),
        ];
    }
}
