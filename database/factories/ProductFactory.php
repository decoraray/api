<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition()
    {
        $title = fake()->unique()->words(3, true);

        return [
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title) . '-' . fake()->unique()->numberBetween(1, 100000),
            'active' => true,
        ];
    }
}
