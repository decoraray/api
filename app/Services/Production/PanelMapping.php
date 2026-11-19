<?php

namespace App\Services\Production;

use App\Enums\StockItemKind;
use App\Models\PanelMaterial;
use App\Models\StockItem;
use App\Support\Production\Present;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Which sheet and edge band stock item each panel material is cut from. Stored as JSON on designs and work orders:
 * [{panel_material_id, sheet_stock_item_id, edge_band_stock_item_id}].
 */
class PanelMapping
{
    public static function rules(): array
    {
        return [
            'mapping' => ['sometimes', 'nullable', 'array'],
            'mapping.*.panel_material_id' => ['required', 'integer', 'distinct', Rule::exists('panel_materials', 'id')],
            'mapping.*.sheet_stock_item_id' => ['required', 'integer', Rule::exists('stock_items', 'id')->where('kind', StockItemKind::MDF_SHEET->value)],
            'mapping.*.edge_band_stock_item_id' => ['nullable', 'integer', Rule::exists('stock_items', 'id')->where('kind', StockItemKind::EDGE_BAND->value)],
        ];
    }

    public static function messages(): array
    {
        return [
            'mapping.*.sheet_stock_item_id.required' => 'برای هر ورق، قلم انبار ورق را انتخاب کنید.',
            'mapping.*.sheet_stock_item_id.exists' => 'قلم ورق باید از نوع ورق MDF باشد.',
            'mapping.*.edge_band_stock_item_id.exists' => 'قلم نوار لبه باید از نوع نوار لبه باشد.',
        ];
    }

    /** The validated request mapping in its stored shape. */
    public static function normalize(array $rows): array
    {
        return collect($rows)->map(fn ($row) => [
            'panel_material_id' => (int) $row['panel_material_id'],
            'sheet_stock_item_id' => (int) $row['sheet_stock_item_id'],
            'edge_band_stock_item_id' => isset($row['edge_band_stock_item_id']) ? (int) $row['edge_band_stock_item_id'] : null,
        ])->values()->all();
    }

    /**
     * Stock items per panel material for `proposeBomLines`, failing with 422 naming each panel material of the
     * parts that has no sheet item.
     *
     * @return array<int, array{sheet: StockItem, edge_band: ?StockItem}>
     */
    public static function resolve(?array $stored, Collection $parts): array
    {
        $rows = collect($stored ?? []);
        $items = StockItem::whereIn('id', $rows->flatMap(fn ($r) => [$r['sheet_stock_item_id'] ?? null, $r['edge_band_stock_item_id'] ?? null])->filter())
            ->get()->keyBy('id');

        $mapping = [];
        foreach ($rows as $row) {
            $sheet = $items->get($row['sheet_stock_item_id'] ?? 0);
            if ($sheet) {
                $mapping[$row['panel_material_id']] = [
                    'sheet' => $sheet,
                    'edge_band' => $items->get($row['edge_band_stock_item_id'] ?? 0),
                ];
            }
        }

        if (method_exists($parts, 'loadMissing')) {
            $parts->loadMissing('panelMaterial');
        }
        $missing = $parts->pluck('panelMaterial')->unique('id')->reject(fn (PanelMaterial $m) => isset($mapping[$m->id]));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'mapping' => 'قلم ورق برای «' . $missing->pluck('name')->join('»، «') . '» انتخاب نشده است.',
            ]);
        }

        return $mapping;
    }

    /** The stored mapping with stock item names, for prefilling the dialog. */
    public static function present(?array $stored): array
    {
        $rows = collect($stored ?? []);
        $items = StockItem::whereIn('id', $rows->flatMap(fn ($r) => [$r['sheet_stock_item_id'] ?? null, $r['edge_band_stock_item_id'] ?? null])->filter())
            ->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'panel_material_id' => $row['panel_material_id'],
            'sheet_stock_item' => ($s = $items->get($row['sheet_stock_item_id'] ?? 0)) ? Present::stockItem($s) : null,
            'edge_band_stock_item' => ($e = $items->get($row['edge_band_stock_item_id'] ?? 0)) ? Present::stockItem($e) : null,
        ])->values()->all();
    }

    /** Proposed lines as JSON. */
    public static function presentLines(array $lines): array
    {
        return array_map(fn ($line) => [
            'stock_item' => Present::stockItem($line['stock_item']),
            'quantity' => $line['quantity'],
        ], $lines);
    }
}
