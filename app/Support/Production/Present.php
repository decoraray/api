<?php

namespace App\Support\Production;

use App\Models\BomLine;
use App\Models\ProductDesign;
use App\Models\StockItem;
use App\Services\Production\Drafts\DraftDesignService;
use App\Services\Production\Drafts\TemplateRegistry;

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
            'approved' => $design->isApproved(),
            'approved_at' => $design->approved_at?->toIso8601String(),
            'approved_by' => $design->approved_by ? ['id' => $design->approved_by, 'name' => $design->approver?->name] : null,
            'parts_count' => $design->parts_count ?? $design->parts()->count(),
            'bom_count' => $design->bom_lines_count ?? $design->bomLines()->count(),
            'updated_at' => $design->updated_at?->toIso8601String(),
            'draft' => self::draft($design),
        ];
    }

    /**
     * How a design was generated from a template («پیش‌نویس خودکار»), or null for a hand-written design. `pending` is
     * true until it is approved; `edited` when its parts or BOM changed by hand since it was last generated.
     */
    public static function draft(ProductDesign $design): ?array
    {
        if ($design->draft_template === null) {
            return null;
        }

        $params = $design->draft_params ?? [];

        return [
            'template' => $design->draft_template,
            'template_name' => app(TemplateRegistry::class)->get($design->draft_template)?->name(),
            'params' => array_diff_key($params, array_flip(['guessed', 'source_dimensions'])),
            'guessed' => (bool) ($params['guessed'] ?? false),
            'source_dimensions' => $params['source_dimensions'] ?? null,
            'edited' => app(DraftDesignService::class)->editedByHand($design),
            'pending' => $design->isDraft(),
        ];
    }
}
