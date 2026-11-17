<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Item;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkOrderFactory extends Factory
{
    public function definition()
    {
        return [
            'order_id' => Order::factory()->state(['status' => OrderStatus::CONFIRMED->value]),
            'item_id' => fn (array $attributes) => Item::create([
                'title' => 'میز تحریر',
                'slug' => 'custom',
                'price' => 1000000,
                'quantity' => 1,
                'order_id' => $attributes['order_id'],
            ])->id,
            'number' => fn (array $attributes) => 'WO-' . $attributes['order_id'] . '-' . $attributes['item_id'],
            'title' => 'میز تحریر',
            'quantity' => 1,
            'state' => 'waiting',
        ];
    }
}
