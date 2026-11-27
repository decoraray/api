<?php

namespace App\Services\Production;

use App\Models\BomLine;
use App\Models\CostAdder;
use App\Models\DesignAdder;
use App\Models\ProductDesign;
use App\Models\StockItem;
use Illuminate\Support\Collection;

/**
 * What one unit of a design costs at today's prices: its material lines at each item's current average cost plus
 * its service adders at their unit cost, rounded to the Toman. Only for users with `costs.view`; callers check.
 */
class DesignEstimate
{
    /**
     * `missing` lists the line items that have no average cost yet (never received), so the total understates them.
     *
     * @param array<int, array{stock_item_id: int, quantity: float}> $lines
     * @param array<int, array{cost_adder_id: int, quantity: float}> $adders
     * @return array{lines: array<int, int>, material_cost: int, adders: array<int, int>, adders_cost: int, total: int, missing: array<int, array{id: int, name: string}>}
     */
    public function costs(array $lines, array $adders): array
    {
        $stock = StockItem::whereIn('id', array_column($lines, 'stock_item_id'))->get(['id', 'name', 'average_cost'])->keyBy('id');
        $unitCosts = CostAdder::whereIn('id', array_column($adders, 'cost_adder_id'))->pluck('unit_cost', 'id');

        return $this->compute($lines, $adders, $stock, $unitCosts);
    }

    /** costs() with the stock items and adder unit costs already loaded, both keyed by id. */
    public function costsUsing(array $lines, array $adders, Collection $stock, Collection $unitCosts): array
    {
        return $this->compute($lines, $adders, $stock, $unitCosts);
    }

    /**
     * The saved costs of many designs with four queries, for reports.
     *
     * @param array<int, int> $designIds
     * @return array<int, array> costs() per design id
     */
    public function manyDesignCosts(array $designIds): array
    {
        $lines = BomLine::whereIn('design_id', $designIds)->get(['design_id', 'stock_item_id', 'quantity'])->groupBy('design_id');
        $adders = DesignAdder::whereIn('design_id', $designIds)->get(['design_id', 'cost_adder_id', 'quantity'])->groupBy('design_id');
        $stock = StockItem::whereIn('id', $lines->flatten()->pluck('stock_item_id')->unique()->all())->get(['id', 'name', 'average_cost'])->keyBy('id');
        $unitCosts = CostAdder::whereIn('id', $adders->flatten()->pluck('cost_adder_id')->unique()->all())->pluck('unit_cost', 'id');

        $out = [];
        foreach ($designIds as $id) {
            $out[$id] = $this->compute(
                ($lines[$id] ?? collect())->map(fn ($l) => ['stock_item_id' => $l->stock_item_id, 'quantity' => $l->quantity])->all(),
                ($adders[$id] ?? collect())->map(fn ($a) => ['cost_adder_id' => $a->cost_adder_id, 'quantity' => $a->quantity])->all(),
                $stock,
                $unitCosts,
            );
        }

        return $out;
    }

    private function compute(array $lines, array $adders, Collection $stock, Collection $unitCosts): array
    {
        $average = fn ($id) => (int) ($stock[$id]->average_cost ?? 0);

        $lineCosts = [];
        $missing = [];
        foreach ($lines as $line) {
            $id = (int) $line['stock_item_id'];
            $lineCosts[$id] = (int) round((float) $line['quantity'] * $average($id));
            if ((float) $line['quantity'] > 0 && $stock->has($id) && $average($id) <= 0) {
                $missing[$id] = ['id' => $id, 'name' => $stock[$id]->name];
            }
        }
        $adderCosts = [];
        foreach ($adders as $adder) {
            $adderCosts[(int) $adder['cost_adder_id']] = (int) round((float) $adder['quantity'] * (int) ($unitCosts[$adder['cost_adder_id']] ?? 0));
        }

        $material = array_sum($lineCosts);
        $added = array_sum($adderCosts);

        return ['lines' => $lineCosts, 'material_cost' => $material, 'adders' => $adderCosts, 'adders_cost' => $added, 'total' => $material + $added, 'missing' => array_values($missing)];
    }

    /** The saved design's estimated cost per unit. */
    public function forDesign(ProductDesign $design): int
    {
        return $this->designCosts($design)['total'];
    }

    /** The saved design's full cost breakdown, as costs() gives it. */
    public function designCosts(ProductDesign $design): array
    {
        $lines = $design->bomLines()->get(['stock_item_id', 'quantity'])
            ->map(fn ($l) => ['stock_item_id' => $l->stock_item_id, 'quantity' => $l->quantity])->all();
        $adders = $design->adders()->get(['cost_adder_id', 'quantity'])
            ->map(fn ($a) => ['cost_adder_id' => $a->cost_adder_id, 'quantity' => $a->quantity])->all();

        return $this->costs($lines, $adders);
    }
}
