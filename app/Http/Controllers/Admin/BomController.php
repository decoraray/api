<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BomLine;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BomController extends Controller
{
    /** The BOM that applies: the variation's own when it has one, else the product's. */
    public function show(Request $request, Product $product)
    {
        $data = $request->validate([
            'variation_id' => ['nullable', 'integer', Rule::exists('variations', 'id')->where('product_id', $product->id)],
        ]);

        [$lines, $source] = BomLine::resolve($product->id, $data['variation_id'] ?? null);

        return response()->json(['data' => $this->present($lines), 'source' => $source]);
    }

    /** Replaces the whole BOM of the product, or of one variation. An empty list clears it. */
    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'variation_id' => ['nullable', 'integer', Rule::exists('variations', 'id')->where('product_id', $product->id)],
            'lines' => ['present', 'array'],
            'lines.*.stock_item_id' => ['required', 'integer', 'distinct', Rule::exists('stock_items', 'id')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'lines.*.note' => ['nullable', 'string', 'max:190'],
        ], [
            'lines.*.stock_item_id.distinct' => 'هر قلم انبار فقط یک بار در فرمول ساخت می‌آید.',
        ]);

        $variationId = $data['variation_id'] ?? null;

        DB::transaction(function () use ($product, $variationId, $data) {
            $scope = BomLine::where('product_id', $product->id);
            $variationId ? $scope->where('variation_id', $variationId) : $scope->whereNull('variation_id');
            $scope->delete();

            foreach ($data['lines'] as $line) {
                BomLine::create([
                    'product_id' => $product->id,
                    'variation_id' => $variationId,
                    'stock_item_id' => $line['stock_item_id'],
                    'quantity' => $line['quantity'],
                    'note' => $line['note'] ?? null,
                ]);
            }
        });

        activity()->performedOn($product)->causedBy($request->user())
            ->withProperties(['variation_id' => $variationId, 'lines' => count($data['lines'])])
            ->log('bom-updated');

        [$lines, $source] = BomLine::resolve($product->id, $variationId);

        return response()->json(['data' => $this->present($lines), 'source' => $source]);
    }

    private function present($lines): array
    {
        return $lines->map(fn (BomLine $line) => [
            'id' => $line->id,
            'stock_item' => [
                'id' => $line->stockItem->id,
                'code' => $line->stockItem->code,
                'name' => $line->stockItem->name,
                'unit' => $line->stockItem->unit->value,
                'unit_label' => $line->stockItem->unit->label(),
            ],
            'quantity' => $line->quantity,
            'note' => $line->note,
        ])->values()->all();
    }
}
