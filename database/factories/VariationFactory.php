<?php

namespace Database\Factories;

use App\Models\Color;
use App\Models\Material;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class VariationFactory extends Factory
{
    public function definition()
    {
        return [
            'product_id' => Product::factory(),
            'color_id' => Color::factory(),
            'material_id' => Material::factory(),
            'size' => fake()->randomElement(['S', 'M', 'L']),
            'stock' => fake()->numberBetween(1, 50),
            'weight' => fake()->numberBetween(100, 5000),
            'price' => fake()->numberBetween(100000, 5000000),
            'active' => true,
        ];
    }
}
