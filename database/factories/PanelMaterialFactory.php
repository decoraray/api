<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PanelMaterialFactory extends Factory
{
    public function definition()
    {
        return [
            'name' => 'ورق ' . fake()->unique()->numerify('###'),
            'thickness_mm' => 16,
            'sheet_length_mm' => 3660,
            'sheet_width_mm' => 1830,
            'waste_percent' => 20,
            'active' => true,
        ];
    }
}
