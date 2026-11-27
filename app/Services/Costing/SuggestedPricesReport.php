<?php

namespace App\Services\Costing;

use App\Models\Variation;

/**
 * Every active variation of an active product that has a design to build from, with its current price beside the cost
 * and suggested price of that design in the variation's material. Worked out in memory from batched queries (a few
 * hundred rows).
 */
class SuggestedPricesReport
{
    public function __construct(private VariationEstimate $estimate)
    {
    }

    /** @return array<int, array> rows sorted by gap, lowest first, rows without a gap last */
    public function rows(?string $search = null): array
    {
        $variations = Variation::query()
            ->where('active', true)
            ->whereHas('product', fn ($q) => $q->where('active', true)
                ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"))))
            // Only those with a design of their own or a product default; the rest have nothing to build from.
            ->where(fn ($q) => $q->whereNotNull('design_id')
                ->orWhereHas('product.designs', fn ($d) => $d->where('is_default', true)))
            ->with(['product:id,title,slug', 'material', 'design', 'color:id,name'])
            ->get();

        $estimates = $this->estimate->forVariations($variations);

        $rows = $variations->toBase()->map(function (Variation $v) use ($estimates) {
            $e = $estimates[$v->id];

            return [
                'product' => ['id' => $v->product->id, 'name' => $v->product->title],
                'variation' => ['id' => $v->id, 'size' => $v->size, 'color' => $v->color?->name],
                'material' => $e['material'],
                'design' => $e['design'],
                'price' => $e['price'],
                'estimated_cost' => $e['estimated_cost'],
                'suggested_price' => $e['suggested_price'],
                'gap' => $e['price'] !== null && $e['suggested_price'] !== null ? $e['price'] - $e['suggested_price'] : null,
                'margin' => $e['margin'],
                'incomplete' => $e['incomplete'],
            ];
        });

        return $rows->sort(fn ($a, $b) => [$a['gap'] === null, $a['gap'], $a['product']['name']] <=> [$b['gap'] === null, $b['gap'], $b['product']['name']])
            ->values()->all();
    }
}
