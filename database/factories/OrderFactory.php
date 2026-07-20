<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Shipping;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    public function definition()
    {
        return [
            'uuid' => uniqid(),
            'user_id' => User::factory(),
            'shipping_id' => Shipping::factory(),
            'address' => fake()->address(),
            'city' => fake()->city(),
            'county' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'phone' => fake()->numerify('9#########'),
            'status' => OrderStatus::PENDING->value,
            'total' => fake()->numberBetween(100000, 5000000),
            'payment_method' => 'cash',
        ];
    }
}
