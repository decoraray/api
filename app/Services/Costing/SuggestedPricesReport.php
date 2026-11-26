<?php

namespace App\Services\Costing;

use App\Models\ProductDesign;
use App\Services\Production\DesignEstimate;

/**
 * Every active variation of an active product that has a default design, with its current price beside the
 * design's estimated cost and suggested price. Worked out in memory from batched queries (a few hundred rows).
 */
class SuggestedPricesReport
{
    public function __construct(private DesignEstimate $estimate, private SuggestedPrice $pricing)
    {
    }

    /** @return array<int, array> rows sorted by gap, lowest first, rows without a gap last */
    public function rows(?string $search = null): array
    {
        $designs = ProductDesign::query()
            ->whereNotNull('variation_id')
            ->whereHas('variation', fn ($q) => $q->where('active', true))
            ->whereHas('product', fn ($q) => $q->where('active', true)
                ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"))))
            ->with(['product:id,title,slug', 'variation:id,product_id,size,color_id,price', 'variation.color:id,name'])
            ->get(['id', 'name', 'product_id', 'variation_id']);

        $costs = $this->estimate->manyDesignCosts($designs->pluck('id')->all());
        $multiplier = $this->pricing->multiplier();
        $rounding = $this->pricing->rounding();

        $rows = $designs->map(function (ProductDesign $d) use ($costs, $multiplier, $rounding) {
            $cost = $costs[$d->id]['total'];
            $price = (int) $d->variation->price ?: null;
            $suggested = $this->pricing->for($cost, $multiplier, $rounding);

            return [
                'product' => ['id' => $d->product->id, 'name' => $d->product->title],
                'variation' => ['id' => $d->variation->id, 'size' => $d->variation->size, 'color' => $d->variation->color?->name],
                'design' => ['id' => $d->id, 'name' => $d->name],
                'price' => $price,
                'estimated_cost' => $cost,
                'suggested_price' => $suggested,
                'gap' => $price !== null && $suggested !== null ? $price - $suggested : null,
                'margin' => $cost > 0 ? SuggestedPrice::margin($price, $cost) : null,
                'incomplete' => $costs[$d->id]['missing'] !== [],
            ];
        });

        return $rows->sort(fn ($a, $b) => [$a['gap'] === null, $a['gap'], $a['product']['name']] <=> [$b['gap'] === null, $b['gap'], $b['product']['name']])
            ->values()->all();
    }
}
