<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CityFactory extends Factory
{
    public function definition()
    {
        $title = fake()->unique()->city();

        return [
            'user_id' => User::factory(),
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title) . '-' . fake()->unique()->numberBetween(1, 100000),
        ];
    }
}
