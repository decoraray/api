<?php

namespace Tests\Feature\CostAdders;

use App\Models\CostAdder;
use App\Models\StockItem;
use Tests\Feature\Costing\CostingTestCase;

abstract class AdderTestCase extends CostingTestCase
{
    protected function serviceAdder(string $name, string $group = 'vacuum', string $unit = 'square_metre', int $cost = 350000, array $over = []): CostAdder
    {
        return CostAdder::create(array_merge([
            'name' => $name, 'group' => $group, 'basis' => 'service', 'unit' => $unit, 'unit_cost' => $cost,
        ], $over));
    }

    protected function stockAdder(string $name, StockItem $item, string $group = 'magnet', ?int $length = null, array $over = []): CostAdder
    {
        return CostAdder::create(array_merge([
            'name' => $name, 'group' => $group, 'basis' => 'stock', 'stock_item_id' => $item->id, 'length_cm' => $length,
        ], $over));
    }

    protected function rail(int $length, float $quantity = 50): StockItem
    {
        return $this->stock($quantity, ['code' => "RAIL-{$length}", 'name' => "ریل {$length}"], 120000);
    }
}
