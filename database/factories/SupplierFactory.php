<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierFactory extends Factory
{
    public function definition()
    {
        return [
            'name' => 'ام‌دی‌اف ' . fake()->unique()->numerify('###'),
            'phone' => fake()->numerify('0912#######'),
            'active' => true,
        ];
    }
}
