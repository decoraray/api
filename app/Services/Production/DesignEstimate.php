<?php

namespace App\Services\Production;

use App\Models\CostAdder;
use App\Models\ProductDesign;
use App\Models\StockItem;

/**
 * What one unit of a design costs at today's prices: its material lines at each item's current average cost plus
 * its service adders at their unit cost, rounded to the Toman. Only for users with `costs.view`; callers check.
 */
class DesignEstimate
{
    /**
     * @param array<int, array{stock_item_id: int, quantity: float}> $lines
     * @param array<int, array{cost_adder_id: int, quantity: float}> $adders
     * @return array{lines: array<int, int>, material_cost: int, adders: array<int, int>, adders_cost: int, total: int}
     */
    public function costs(array $lines, array $adders): array
    {
        $items = StockItem::whereIn('id', array_column($lines, 'stock_item_id'))->pluck('average_cost', 'id');
        $costs = CostAdder::whereIn('id', array_column($adders, 'cost_adder_id'))->pluck('unit_cost', 'id');

        $lineCosts = [];
        foreach ($lines as $line) {
            $lineCosts[(int) $line['stock_item_id']] = (int) round((float) $line['quantity'] * (int) ($items[$line['stock_item_id']] ?? 0));
        }
        $adderCosts = [];
        foreach ($adders as $adder) {
            $adderCosts[(int) $adder['cost_adder_id']] = (int) round((float) $adder['quantity'] * (int) ($costs[$adder['cost_adder_id']] ?? 0));
        }

        $material = array_sum($lineCosts);
        $added = array_sum($adderCosts);

        return ['lines' => $lineCosts, 'material_cost' => $material, 'adders' => $adderCosts, 'adders_cost' => $added, 'total' => $material + $added];
    }

    /** The saved design's estimated cost per unit. */
    public function forDesign(ProductDesign $design): int
    {
        $lines = $design->bomLines()->get(['stock_item_id', 'quantity'])
            ->map(fn ($l) => ['stock_item_id' => $l->stock_item_id, 'quantity' => $l->quantity])->all();
        $adders = $design->adders()->get(['cost_adder_id', 'quantity'])
            ->map(fn ($a) => ['cost_adder_id' => $a->cost_adder_id, 'quantity' => $a->quantity])->all();

        return $this->costs($lines, $adders)['total'];
    }
}
