<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CuttingOp;
use App\Enums\StockItemKind;
use App\Http\Controllers\Controller;
use App\Models\PanelMaterial;
use App\Models\Product;
use App\Models\ProductPart;
use App\Models\StockItem;
use App\Models\WorkOrder;
use App\Services\Production\CuttingListService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CuttingListController extends Controller
{
    public function __construct(private CuttingListService $cutting)
    {
    }

    /** The cutting list that applies: the variation's own when it has one, else the product's. */
    public function show(Request $request, Product $product)
    {
        $variationId = $this->variationId($request, $product);

        return response()->json($this->body($product, $variationId));
    }

    /** Replaces the whole cutting list of the product, or of one variation. An empty list clears it. */
    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'variation_id' => ['nullable', 'integer', Rule::exists('variations', 'id')->where('product_id', $product->id)],
            'parts' => ['present', 'array', 'max:200'],
            'parts.*.name' => ['required', 'string', 'max:100'],
            'parts.*.length_mm' => ['required', 'integer', 'min:1', 'max:5000'],
            'parts.*.width_mm' => ['required', 'integer', 'min:1', 'max:5000'],
            'parts.*.count' => ['required', 'integer', 'min:1', 'max:1000'],
            'parts.*.panel_material_id' => ['required', 'integer', Rule::exists('panel_materials', 'id')->where('active', true)],
            'parts.*.edge_length' => ['required', 'integer', 'min:0', 'max:2'],
            'parts.*.edge_width' => ['required', 'integer', 'min:0', 'max:2'],
            'parts.*.grain_locked' => ['sometimes', 'boolean'],
            'parts.*.ops' => ['sometimes', 'array'],
            'parts.*.ops.*' => [Rule::enum(CuttingOp::class)],
            'parts.*.note' => ['nullable', 'string', 'max:190'],
        ], [
            'parts.*.name.required' => 'نام هر قطعه را وارد کنید.',
            'parts.*.length_mm.*' => 'طول قطعه باید بین ۰.۱ و ۵۰۰ سانت باشد.',
            'parts.*.width_mm.*' => 'عرض قطعه باید بین ۰.۱ و ۵۰۰ سانت باشد.',
            'parts.*.count.*' => 'تعداد هر قطعه دست‌کم ۱ است.',
            'parts.*.panel_material_id.*' => 'ورق هر قطعه را از ورق‌های فعال انتخاب کنید.',
            'parts.*.edge_length.*' => 'نوار لبه زیر هر بُعد ۰ تا ۲ خط است.',
            'parts.*.edge_width.*' => 'نوار لبه زیر هر بُعد ۰ تا ۲ خط است.',
            'parts.*.ops.*.*' => 'عملیات قطعه نامعتبر است.',
        ]);

        $variationId = $data['variation_id'] ?? null;

        DB::transaction(function () use ($product, $variationId, $data) {
            $scope = ProductPart::where('product_id', $product->id);
            $variationId ? $scope->where('variation_id', $variationId) : $scope->whereNull('variation_id');
            $scope->delete();

            foreach (array_values($data['parts']) as $position => $part) {
                ProductPart::create([
                    'product_id' => $product->id,
                    'variation_id' => $variationId,
                    'position' => $position,
                    'name' => $part['name'],
                    'length_mm' => $part['length_mm'],
                    'width_mm' => $part['width_mm'],
                    'count' => $part['count'],
                    'panel_material_id' => $part['panel_material_id'],
                    'edge_length' => $part['edge_length'],
                    'edge_width' => $part['edge_width'],
                    'grain_locked' => $part['grain_locked'] ?? false,
                    'ops' => array_values(array_unique($part['ops'] ?? [])),
                    'note' => $part['note'] ?? null,
                ]);
            }
        });

        activity()->performedOn($product)->causedBy($request->user())
            ->withProperties(['variation_id' => $variationId, 'parts' => count($data['parts'])])
            ->log('cutting-list-updated');

        return response()->json($this->body($product, $variationId));
    }

    /** Proposed BOM lines for one unit, from the cutting list and a sheet / edge band item per panel material. Saves nothing. */
    public function bomLines(Request $request, Product $product)
    {
        $data = $request->validate([
            'variation_id' => ['nullable', 'integer', Rule::exists('variations', 'id')->where('product_id', $product->id)],
            'mapping' => ['present', 'array'],
            'mapping.*.panel_material_id' => ['required', 'integer', 'distinct'],
            'mapping.*.sheet_stock_item_id' => ['required', 'integer', Rule::exists('stock_items', 'id')->where('kind', StockItemKind::MDF_SHEET->value)],
            'mapping.*.edge_band_stock_item_id' => ['nullable', 'integer', Rule::exists('stock_items', 'id')->where('kind', StockItemKind::EDGE_BAND->value)],
        ], [
            'mapping.*.sheet_stock_item_id.required' => 'برای هر ورق، قلم انبار ورق را انتخاب کنید.',
            'mapping.*.sheet_stock_item_id.exists' => 'قلم ورق باید از نوع ورق MDF باشد.',
            'mapping.*.edge_band_stock_item_id.exists' => 'قلم نوار لبه باید از نوع نوار لبه باشد.',
        ]);

        [$parts] = $this->cutting->resolve($product->id, $data['variation_id'] ?? null);

        $items = StockItem::whereIn('id', collect($data['mapping'])->flatMap(fn ($m) => [$m['sheet_stock_item_id'], $m['edge_band_stock_item_id'] ?? null])->filter())
            ->get()->keyBy('id');
        $mapping = [];
        foreach ($data['mapping'] as $row) {
            $mapping[$row['panel_material_id']] = [
                'sheet' => $items[$row['sheet_stock_item_id']],
                'edge_band' => isset($row['edge_band_stock_item_id']) ? $items[$row['edge_band_stock_item_id']] : null,
            ];
        }

        $missing = $parts->pluck('panelMaterial')->unique('id')->reject(fn (PanelMaterial $m) => isset($mapping[$m->id]));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'mapping' => 'قلم ورق برای «' . $missing->pluck('name')->join('»، «') . '» انتخاب نشده است.',
            ]);
        }

        $lines = $this->cutting->proposeBomLines($parts, $mapping);

        return response()->json(['data' => array_map(fn ($line) => [
            'stock_item' => [
                'id' => $line['stock_item']->id,
                'code' => $line['stock_item']->code,
                'name' => $line['stock_item']->name,
                'unit' => $line['stock_item']->unit->value,
                'unit_label' => $line['stock_item']->unit->label(),
            ],
            'quantity' => $line['quantity'],
        ], $lines)]);
    }

    /** The cutting sheet of a work order: its product's cutting list × the work order quantity, grouped by panel material. */
    public function workOrder(WorkOrder $workOrder)
    {
        $workOrder->loadMissing('item');
        $item = $workOrder->item;
        [$parts, $source] = $this->cutting->resolve($item?->product_id, $item?->variation_id);
        $quantity = (int) $workOrder->quantity;

        $groups = $parts->groupBy('panel_material_id')->map(fn ($group) => [
            'panel_material' => $this->cutting->presentMaterial($group->first()->panelMaterial),
            'parts' => $group->map(fn ($part) => $this->cutting->presentPart($part, $quantity))->values(),
        ])->values();

        return response()->json([
            'work_order' => [
                'id' => $workOrder->id,
                'number' => $workOrder->number,
                'quantity' => $quantity,
                'title' => $item?->title,
                'color' => $item?->color,
                'dimensions' => $item?->dimensions,
            ],
            'source' => $source,
            'updated_at' => $parts->max('updated_at')?->toIso8601String(),
            'groups' => $groups,
            'summary' => $this->cutting->summary($parts, $quantity),
        ]);
    }

    private function body(Product $product, ?int $variationId): array
    {
        [$parts, $source] = $this->cutting->resolve($product->id, $variationId);

        return [
            'data' => $parts->map(fn ($part) => $this->cutting->presentPart($part))->values(),
            'source' => $source,
            'summary' => $this->cutting->summary($parts),
            'warnings' => $this->cutting->fitWarnings($product, $parts),
        ];
    }

    private function variationId(Request $request, Product $product): ?int
    {
        $data = $request->validate([
            'variation_id' => ['nullable', 'integer', Rule::exists('variations', 'id')->where('product_id', $product->id)],
        ]);

        return $data['variation_id'] ?? null;
    }
}
