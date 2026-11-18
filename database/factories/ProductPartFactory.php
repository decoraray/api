<?php

namespace Database\Factories;

use App\Models\PanelMaterial;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductPartFactory extends Factory
{
    public function definition()
    {
        return [
            'product_id' => Product::factory(),
            'variation_id' => null,
            'position' => 0,
            'name' => 'بغل',
            'length_mm' => 500,
            'width_mm' => 380,
            'count' => 2,
            'panel_material_id' => PanelMaterial::factory(),
            'edge_length' => 0,
            'edge_width' => 0,
            'grain_locked' => false,
            'ops' => [],
            'note' => null,
        ];
    }
}
