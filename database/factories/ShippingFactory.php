<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ShippingFactory extends Factory
{
    public function definition()
    {
        return [
            'name' => fake()->words(2, true),
            'default_price' => fake()->numberBetween(20000, 100000),
            'additional_percent' => 0,
        ];
    }
}
