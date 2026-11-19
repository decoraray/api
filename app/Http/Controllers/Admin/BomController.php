<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Production\DesignService;
use App\Support\Production\Present;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @deprecated Product-level BOM routes kept while the admin moves to designs (designs/{design}/bom).
 * They read and write the variation's default design, else the product's default design.
 */
class BomController extends Controller
{
    public function __construct(private DesignService $designs)
    {
    }

    public function show(Request $request, Product $product)
    {
        $variationId = $this->variationId($request, $product);
        [$design, $source] = $this->find($product, $variationId);

        return response()->json(['data' => $this->present($design), 'source' => $source]);
    }

    public function update(Request $request, Product $product)
    {
        $variationId = $this->variationId($request, $product);
        $data = $request->validate(DesignController::bomRules(), DesignController::bomMessages());

        $design = $this->designs->defaultFor($product, $variationId, true);
        $this->designs->replaceBom($design, $data['lines']);

        activity()->performedOn($design)->causedBy($request->user())
            ->withProperties(['product_id' => $product->id, 'variation_id' => $variationId, 'lines' => count($data['lines'])])
            ->log('bom-updated');

        [$design, $source] = $this->find($product, $variationId);

        return response()->json(['data' => $this->present($design), 'source' => $source]);
    }

    /** The variation's default design when it has a BOM, else the product's default. */
    private function find(Product $product, ?int $variationId): array
    {
        if ($variationId && ($own = $this->designs->defaultFor($product, $variationId)) && $own->bomLines()->exists()) {
            return [$own, 'variation'];
        }
        $shared = $this->designs->defaultFor($product, null);

        return [$shared, $shared && $shared->bomLines()->exists() ? 'product' : 'none'];
    }

    private function present($design): array
    {
        return $design ? $design->bomLines()->with('stockItem')->get()->map(fn ($l) => Present::bomLine($l))->values()->all() : [];
    }

    private function variationId(Request $request, Product $product): ?int
    {
        return $request->validate([
            'variation_id' => ['nullable', 'integer', Rule::exists('variations', 'id')->where('product_id', $product->id)],
        ])['variation_id'] ?? null;
    }
}
