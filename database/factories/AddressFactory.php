<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AddressFactory extends Factory
{
    public function definition()
    {
        return [
            'uuid' => Str::uuid(),
            'title' => 'Home',
            'name' => fake()->name(),
            'phone' => fake()->numerify('9#########'),
            'address' => fake()->address(),
            'city' => fake()->city(),
            'county' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'city_id' => City::factory(),
            'user_id' => User::factory(),
        ];
    }
}
