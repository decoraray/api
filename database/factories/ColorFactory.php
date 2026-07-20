<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ColorFactory extends Factory
{
    public function definition()
    {
        return [
            'name' => fake()->unique()->colorName(),
            'hex' => fake()->hexColor(),
        ];
    }
}
