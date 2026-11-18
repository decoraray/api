<?php

namespace App\Services\Production;

use App\Models\PanelMaterial;
use App\Models\Product;
use App\Models\ProductPart;
use App\Models\StockItem;
use App\Support\PersianText;
use Illuminate\Support\Collection;

/**
 * A product's cutting list: which list applies, how much sheet and edge band it needs,
 * the BOM lines that follows from it, and parts that cannot fit the product.
 *
 * All sums are in whole mm / mm² and divided once at the end, rounding up: a slightly long
 * reservation is cheaper than a job stopping for one missing sheet.
 */
class CuttingListService
{
    /** Each banded edge is cut this much longer than the side it covers, for trimming. */
    public const EDGE_ALLOWANCE_MM = 30;

    /**
     * The list that applies: the variation's own when it has one, else the product's.
     *
     * @return array{0: Collection<int, ProductPart>, 1: string} parts and their source (variation, product, none)
     */
    public function resolve(?int $productId, ?int $variationId): array
    {
        if (!$productId) {
            return [collect(), 'none'];
        }

        $query = fn () => ProductPart::with('panelMaterial')->where('product_id', $productId)->orderBy('position')->orderBy('id');

        if ($variationId) {
            $own = $query()->where('variation_id', $variationId)->get();
            if ($own->isNotEmpty()) {
                return [$own, 'variation'];
            }
        }

        $shared = $query()->whereNull('variation_id')->get();

        return [$shared, $shared->isEmpty() ? 'none' : 'product'];
    }

    /** Per panel material, in order of first use: pieces, m², estimated sheets and edge band metres for `multiplier` units. */
    public function summary(Collection $parts, int $multiplier = 1): array
    {
        return $this->groups($parts, $multiplier)->map(function (array $group) {
            $material = $group['material'];

            return [
                'panel_material' => $this->presentMaterial($material),
                'pieces' => $group['pieces'],
                'area_m2' => round($group['area_mm2'] / 1_000_000, 3),
                'sheets' => $this->sheets($group['area_mm2'], $material->waste_percent, $material->sheet_length_mm, $material->sheet_width_mm),
                'edge_m' => $this->metres($group['edge_mm']),
            ];
        })->values()->all();
    }

    /**
     * Proposed BOM lines for one unit. `mapping` is keyed by panel material id:
     * ['sheet' => StockItem, 'edge_band' => ?StockItem]. Lines for the same stock item are summed.
     *
     * @return array<int, array{stock_item: StockItem, quantity: float}>
     */
    public function proposeBomLines(Collection $parts, array $mapping): array
    {
        $lines = [];
        $add = function (StockItem $item, float $quantity) use (&$lines) {
            $lines[$item->id] ??= ['stock_item' => $item, 'quantity' => 0.0];
            $lines[$item->id]['quantity'] = round($lines[$item->id]['quantity'] + $quantity, 3);
        };

        foreach ($this->groups($parts, 1) as $materialId => $group) {
            $material = $group['material'];
            $sheet = $mapping[$materialId]['sheet'];
            // The real sheet's size beats the material's default when the stock item records one.
            $length = $sheet->length_mm && $sheet->width_mm ? $sheet->length_mm : $material->sheet_length_mm;
            $width = $sheet->length_mm && $sheet->width_mm ? $sheet->width_mm : $material->sheet_width_mm;
            $add($sheet, $this->sheets($group['area_mm2'], $material->waste_percent, $length, $width));

            $band = $mapping[$materialId]['edge_band'] ?? null;
            if ($band && $group['edge_mm'] > 0) {
                $add($band, $this->metres($group['edge_mm']));
            }
        }

        return array_values($lines);
    }

    /** Parts that do not fit the product's overall dimensions («200*40*40», cm). None when those cannot be read. */
    public function fitWarnings(?Product $product, Collection $parts): array
    {
        $overall = $this->overallMm($product?->dimensions);
        if (!$overall) {
            return [];
        }

        $warnings = [];
        foreach ($parts->values() as $index => $part) {
            $sides = [$part->length_mm, $part->width_mm];
            rsort($sides);
            if ($sides[0] > $overall[0] || $sides[1] > $overall[1]) {
                $warnings[] = [
                    'index' => $index,
                    'part_id' => $part->id,
                    'message' => sprintf('قطعه «%s» (%s × %s سانت) از ابعاد کلی محصول (%s) بزرگ‌تر است.',
                        $part->name, $this->cm($part->length_mm), $this->cm($part->width_mm), $product->dimensions),
                ];
            }
        }

        return $warnings;
    }

    public function presentPart(ProductPart $part, int $multiplier = 1): array
    {
        return [
            'id' => $part->id,
            'position' => $part->position,
            'name' => $part->name,
            'length_mm' => $part->length_mm,
            'width_mm' => $part->width_mm,
            'count' => $part->count * $multiplier,
            'panel_material' => $this->presentMaterial($part->panelMaterial),
            'edge_length' => $part->edge_length,
            'edge_width' => $part->edge_width,
            'grain_locked' => $part->grain_locked,
            'ops' => ($part->ops ?? collect())->map(fn ($op) => $op->value)->values()->all(),
            'note' => $part->note,
        ];
    }

    public function presentMaterial(PanelMaterial $material): array
    {
        return [
            'id' => $material->id,
            'name' => $material->name,
            'thickness_mm' => $material->thickness_mm,
            'sheet_length_mm' => $material->sheet_length_mm,
            'sheet_width_mm' => $material->sheet_width_mm,
            'waste_percent' => $material->waste_percent,
            'active' => $material->active,
        ];
    }

    /** Parts grouped by panel material with integer totals, keyed by material id in order of first use. */
    private function groups(Collection $parts, int $multiplier): Collection
    {
        $groups = collect();
        foreach ($parts as $part) {
            $count = $part->count * $multiplier;
            $group = $groups->get($part->panel_material_id, ['material' => $part->panelMaterial, 'pieces' => 0, 'area_mm2' => 0, 'edge_mm' => 0]);
            $group['pieces'] += $count;
            $group['area_mm2'] += $count * $part->length_mm * $part->width_mm;
            $group['edge_mm'] += $count * (
                $part->edge_length * ($part->length_mm + self::EDGE_ALLOWANCE_MM)
                + $part->edge_width * ($part->width_mm + self::EDGE_ALLOWANCE_MM)
            );
            $groups->put($part->panel_material_id, $group);
        }

        return $groups;
    }

    /** area × (1 + waste%) ÷ sheet area, rounded up to 0.01 sheet. */
    private function sheets(int $areaMm2, int $wastePercent, int $sheetLength, int $sheetWidth): float
    {
        $sheetArea = $sheetLength * $sheetWidth;
        if ($areaMm2 <= 0 || $sheetArea <= 0) {
            return 0.0;
        }

        // hundredths of a sheet = ceil(area × (100 + waste) × 100 / (100 × sheet area))
        $numerator = $areaMm2 * (100 + $wastePercent);
        $hundredths = intdiv($numerator + $sheetArea - 1, $sheetArea);

        return $hundredths / 100;
    }

    /** mm → metres, rounded up to 0.1 m. */
    private function metres(int $mm): float
    {
        return intdiv($mm + 99, 100) / 10;
    }

    /** Overall dimensions in mm, largest first, or null when they are not three numbers. */
    private function overallMm(?string $dimensions): ?array
    {
        $text = PersianText::normalize($dimensions);
        if (!preg_match('/^([\d.]+)\s*[*x×]\s*([\d.]+)\s*[*x×]\s*([\d.]+)$/u', $text, $m)) {
            return null;
        }

        $sides = array_map(fn ($cm) => (int) round((float) $cm * 10), array_slice($m, 1));
        if (min($sides) <= 0) {
            return null;
        }
        rsort($sides);

        return $sides;
    }

    private function cm(int $mm): string
    {
        return rtrim(rtrim(number_format($mm / 10, 1, '.', ''), '0'), '.');
    }
}
