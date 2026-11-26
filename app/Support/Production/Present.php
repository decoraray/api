<?php

namespace App\Support\Production;

use App\Models\BomLine;
use App\Models\CostAdder;
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
            // Sheets and edge band that follow from the parts: shown read-only, recalculated when parts are saved.
            'calculated' => $line->calculated_quantity !== null,
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
            'params' => array_diff_key($params, array_flip(['guessed', 'source_dimensions', 'rules'])),
            'guessed' => (bool) ($params['guessed'] ?? false),
            'source_dimensions' => $params['source_dimensions'] ?? null,
            'edited' => app(DraftDesignService::class)->editedByHand($design),
            'pending' => $design->isDraft(),
            'rules' => self::draftRules($params['rules'] ?? null),
        ];
    }

    /**
     * What the material rules chose for a draft: the words that matched, warnings, and every adder with its quantity
     * (rails and stock-linked ones too). Names only, no cost. Null for drafts made before the rules.
     */
    public static function draftRules(?array $rules): ?array
    {
        if ($rules === null) {
            return null;
        }
        $ids = array_map(fn ($i) => (int) $i[0], $rules['items'] ?? []);
        $names = $ids ? CostAdder::whereIn('id', $ids)->pluck('name', 'id')->all() : [];

        return [
            'matches' => array_values($rules['matches'] ?? []),
            'warnings' => array_values($rules['warnings'] ?? []),
            'adders' => array_map(fn ($i) => ['id' => (int) $i[0], 'name' => $names[(int) $i[0]] ?? '؟', 'quantity' => (float) $i[1]], $rules['items'] ?? []),
        ];
    }
}
