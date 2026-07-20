<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImageFactory extends Factory
{
    public function definition()
    {
        return [
            'image' => 'products/' . fake()->uuid() . '.jpg',
            'product_id' => Product::factory(),
            'sort' => 0,
        ];
    }
}
