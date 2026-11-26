<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductDesignFactory extends Factory
{
    public function definition()
    {
        return [
            'product_id' => Product::factory(),
            'variation_id' => null,
            'name' => 'طرح ' . $this->faker->unique()->numberBetween(1, 1000000),
            'dimensions' => null,
            'note' => null,
            'is_default' => false,
            'active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
