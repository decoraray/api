<?php

namespace App\Services\Production;

use App\Models\Material;
use App\Models\StockItem;
use App\Services\Production\Drafts\MaterialRules;
use App\Support\PersianText;
use Illuminate\Support\Collection;

/**
 * A design's parts as built in a storefront material: parts on a board as thick as the material's body sheet move to
 * that sheet, and its fronts (doors and drawer fronts) to the front sheet when there is one. Other parts, such as the
 * 3 mm back, keep their sheet. Costing and production both use this, so they can never disagree.
 */
class MaterialBuild
{
    /**
     * Unsaved copies of the parts, on the material's sheets. The given parts and their design are not changed.
     *
     * @param ?Collection $sheets stock items keyed by id, to avoid a query per call
     */
    public function parts(Collection $parts, ?Material $material, ?Collection $sheets = null): Collection
    {
        $copies = $parts->map(fn ($part) => $part->replicate())->values();
        if (!$material?->body_sheet_item_id) {
            return $copies;
        }

        $sheets ??= StockItem::whereIn('id', $copies->pluck('stock_item_id')
            ->push($material->body_sheet_item_id)->filter()->unique()->all())->get()->keyBy('id');
        $body = $sheets->get($material->body_sheet_item_id) ?? StockItem::find($material->body_sheet_item_id);
        if (!$body) {
            return $copies;
        }

        foreach ($copies as $copy) {
            $sheet = $sheets->get($copy->stock_item_id);
            if (!$sheet || $sheet->thickness_mm === null || abs((float) $sheet->thickness_mm - (float) $body->thickness_mm) > 0.001) {
                continue;
            }
            $copy->stock_item_id = $material->front_sheet_item_id && $this->isFront($copy->name)
                ? $material->front_sheet_item_id
                : $body->id;
        }

        return $copies;
    }

    private function isFront(?string $name): bool
    {
        $name = PersianText::normalize($name);

        foreach (MaterialRules::FRONT_PARTS as $front) {
            if (str_starts_with($name, PersianText::normalize($front))) {
                return true;
            }
        }

        return false;
    }
}
