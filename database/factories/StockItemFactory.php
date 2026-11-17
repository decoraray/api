<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class StockItemFactory extends Factory
{
    public function definition()
    {
        return [
            'code' => 'ITM-' . fake()->unique()->numerify('#####'),
            'name' => 'لولا',
            'kind' => 'hardware',
            'unit' => 'piece',
            'active' => true,
        ];
    }

    public function mdf(): static
    {
        return $this->state([
            'name' => 'MDF سفید ۱۶',
            'kind' => 'mdf_sheet',
            'unit' => 'sheet',
            'thickness_mm' => 16,
            'color' => 'سفید',
            'length_mm' => 3660,
            'width_mm' => 1830,
            'sheet_type' => 'ملامینه',
        ]);
    }
}
