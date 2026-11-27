<?php

namespace App\Services\Costing;

use App\Models\CostAdder;
use App\Models\Material;
use App\Models\ProductDesign;
use App\Models\StockItem;
use App\Models\Variation;
use App\Services\Production\CuttingListService;
use App\Services\Production\DesignEstimate;
use App\Services\Production\MaterialBuild;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What one unit of a variation costs to build, and the price that suggests: its design (else the product default)
 * built in its material, priced like a design. Nothing is stored, so it follows today's sheet prices. Only for users
 * with `costs.view`; callers check.
 */
class VariationEstimate
{
    public function __construct(
        private DesignEstimate $estimate,
        private SuggestedPrice $pricing,
        private MaterialBuild $build,
        private CuttingListService $cutting,
    ) {
    }

    public function forVariation(Variation $variation): array
    {
        return $this->forVariations(new EloquentCollection([$variation]))[$variation->id];
    }

    /**
     * Estimates for many variations with a fixed number of queries.
     *
     * `$forced` builds every variation from that design instead of resolving each one's own.
     *
     * @return array<int, array> keyed by variation id
     */
    public function forVariations(EloquentCollection $variations, ?ProductDesign $forced = null): array
    {
        $variations->loadMissing(['material', 'design', 'color:id,name']);
        $defaults = $forced ? collect() : ProductDesign::whereIn('product_id', $variations->pluck('product_id')->unique()->all())
            ->where('is_default', true)->get()->keyBy('product_id');

        $designs = new EloquentCollection();
        $chosen = [];
        foreach ($variations as $variation) {
            $design = $forced ?? $variation->design ?? $defaults->get($variation->product_id);
            $chosen[$variation->id] = $design;
            if ($design && !$designs->contains($design)) {
                $designs->push($design);
            }
        }
        $designs->load(['parts', 'bomLines', 'adders']);

        $sheets = StockItem::with('defaultEdgeBand')->get()->keyBy('id');
        $unitCosts = CostAdder::pluck('unit_cost', 'id');
        $multiplier = $this->pricing->multiplier();
        $rounding = $this->pricing->rounding();

        $out = [];
        foreach ($variations as $variation) {
            $design = $chosen[$variation->id] ? $designs->find($chosen[$variation->id]->id) : null;
            $price = (int) $variation->price ?: null;
            $base = [
                'variation_id' => $variation->id,
                'design' => $design ? ['id' => $design->id, 'name' => $design->name] : null,
                'material' => $variation->material ? ['id' => $variation->material->id, 'name' => $variation->material->name] : null,
                'price' => $price,
            ];
            if (!$design) {
                $out[$variation->id] = $base + [
                    'sheets' => [], 'estimated_cost' => null, 'suggested_price' => null, 'margin' => null,
                    'incomplete' => false, 'missing' => [],
                ];
                continue;
            }

            [$lines, $swaps] = $this->lines($design, $variation->material, $sheets);
            $adders = $design->adders->map(fn ($a) => ['cost_adder_id' => $a->cost_adder_id, 'quantity' => $a->quantity])->all();
            $costs = $this->estimate->costsUsing($lines, $adders, $sheets, $unitCosts);

            $out[$variation->id] = $base + [
                'sheets' => $swaps,
                'estimated_cost' => $costs['total'],
                'suggested_price' => $this->pricing->for($costs['total'], $multiplier, $rounding),
                'margin' => $costs['total'] > 0 ? SuggestedPrice::margin($price, $costs['total']) : null,
                'incomplete' => $costs['missing'] !== [],
                'missing' => $costs['missing'],
            ];
        }

        return $out;
    }

    /**
     * The design's material lines when built in the material: sheet and edge band lines calculated from the moved
     * parts, plus what was added by hand or by an adder.
     *
     * @return array{0: array, 1: array} [lines for DesignEstimate, the sheets swapped as {from, to}]
     */
    private function lines(ProductDesign $design, ?Material $material, Collection $sheets): array
    {
        $quantities = [];
        $add = function (int $id, float $quantity) use (&$quantities) {
            $quantities[$id] = round(($quantities[$id] ?? 0) + $quantity, 3);
        };

        $parts = $this->build->parts($design->parts, $material, $sheets);
        foreach ($this->cutting->calculatedLines($parts, 1, $sheets) as $id => $line) {
            $add($id, $line['quantity']);
        }
        foreach ($design->bomLines as $line) {
            $hand = round($line->quantity - (float) $line->calculated_quantity, 3);
            if ($hand > 0) {
                $add($line->stock_item_id, $hand);
            }
        }

        $swaps = [];
        foreach ($design->parts->values() as $index => $part) {
            $to = $parts[$index]->stock_item_id;
            if ($to !== $part->stock_item_id && $sheets->has($part->stock_item_id) && $sheets->has($to)) {
                $swaps["{$part->stock_item_id}-{$to}"] = [
                    'from' => ['id' => $part->stock_item_id, 'code' => $sheets[$part->stock_item_id]->code, 'name' => $sheets[$part->stock_item_id]->name],
                    'to' => ['id' => $to, 'code' => $sheets[$to]->code, 'name' => $sheets[$to]->name],
                ];
            }
        }

        $lines = [];
        foreach ($quantities as $id => $quantity) {
            $lines[] = ['stock_item_id' => $id, 'quantity' => $quantity];
        }

        return [$lines, array_values($swaps)];
    }
}
