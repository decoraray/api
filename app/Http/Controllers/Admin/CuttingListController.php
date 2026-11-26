<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductDesign;
use App\Models\WorkOrder;
use App\Services\Production\CuttingListService;
use App\Services\Production\DesignService;
use App\Support\Production\Present;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The work order cutting sheet, and the deprecated product-level cutting list routes kept while the admin moves to
 * designs (designs/{design}/cutting-list). Those read and write the variation's default design, else the product's.
 */
class CuttingListController extends Controller
{
    public function __construct(private CuttingListService $cutting, private DesignService $designs)
    {
    }

    /** @deprecated */
    public function show(Request $request, Product $product)
    {
        return response()->json($this->body($product, $this->variationId($request, $product)));
    }

    /** @deprecated */
    public function update(Request $request, Product $product)
    {
        $variationId = $this->variationId($request, $product);
        $data = $request->validate(CuttingListService::partRules(), CuttingListService::partMessages());

        $design = $this->designs->defaultFor($product, $variationId, true);
        $this->designs->replaceParts($design, $data['parts']);

        activity()->performedOn($design)->causedBy($request->user())
            ->withProperties(['product_id' => $product->id, 'variation_id' => $variationId, 'parts' => count($data['parts'])])
            ->log('cutting-list-updated');

        return response()->json($this->body($product, $variationId));
    }

    /** The cutting sheet of a work order: its own parts × its quantity, grouped by sheet item, and its materials. */
    public function workOrder(WorkOrder $workOrder)
    {
        $workOrder->loadMissing(['item', 'colour']);
        $item = $workOrder->item;
        $parts = $workOrder->parts()->get();
        $this->cutting->loadSheets($parts);
        $quantity = (int) $workOrder->quantity;

        $dimensions = $this->cutting->readableDimensions($workOrder->dimensions)
            ? $workOrder->dimensions
            : ($workOrder->design_id ? ProductDesign::find($workOrder->design_id)?->dimensions : null);

        return response()->json([
            'work_order' => [
                'id' => $workOrder->id,
                'number' => $workOrder->number,
                'quantity' => $quantity,
                'title' => $item?->title ?? $workOrder->title,
                'color' => $workOrder->color,
                'colour' => \App\Support\Orders\LineColour::present($workOrder->colour),
                'dimensions' => $workOrder->dimensions,
                'state' => $workOrder->state->value,
            ],
            'design' => $workOrder->design_name ? ['id' => $workOrder->design_id, 'name' => $workOrder->design_name, 'approved' => (bool) $workOrder->design_approved, 'draft' => (bool) $workOrder->design_draft] : null,
            'customized' => $workOrder->customized,
            'source' => $parts->isEmpty() ? 'none' : 'work_order',
            'updated_at' => $parts->max('updated_at')?->toIso8601String(),
            'parts' => $parts->map(fn ($part) => $this->cutting->presentPart($part))->values(),
            'materials' => $workOrder->materials()->with('stockItem')->get()->map(fn ($m) => [
                'stock_item' => Present::stockItem($m->stockItem),
                'quantity' => $m->planned_quantity,
                'calculated' => $m->calculated_quantity !== null,
                'note' => $m->note,
            ])->values(),
            'warnings' => $this->cutting->fitWarnings($dimensions, $parts),
        ] + $this->cutting->sheet($parts, $quantity));
    }

    /** The variation's default design when it has parts, else the product's default. */
    private function find(Product $product, ?int $variationId): array
    {
        if ($variationId && ($own = $this->designs->defaultFor($product, $variationId)) && $own->parts()->exists()) {
            return [$own, 'variation'];
        }
        $shared = $this->designs->defaultFor($product, null);

        return [$shared, $shared && $shared->parts()->exists() ? 'product' : 'none'];
    }

    private function body(Product $product, ?int $variationId): array
    {
        [$design, $source] = $this->find($product, $variationId);
        $parts = $design ? $design->parts()->get() : collect();
        $this->cutting->loadSheets($parts);
        $dimensions = $design && $this->cutting->readableDimensions($design->dimensions) ? $design->dimensions : $product->dimensions;

        return [
            'data' => $parts->map(fn ($part) => $this->cutting->presentPart($part))->values(),
            'source' => $source,
            'summary' => $this->cutting->summary($parts),
            'warnings' => $this->cutting->fitWarnings($dimensions, $parts),
        ];
    }

    private function variationId(Request $request, Product $product): ?int
    {
        return $request->validate([
            'variation_id' => ['nullable', 'integer', Rule::exists('variations', 'id')->where('product_id', $product->id)],
        ])['variation_id'] ?? null;
    }
}
