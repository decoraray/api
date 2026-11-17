<?php

namespace Tests\Feature\Production;

use App\Enums\OrderStatus;
use App\Models\Item;
use App\Models\Order;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Production\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ProductionTestCase extends TestCase
{
    use RefreshDatabase;

    protected function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function header(string|User $role): array
    {
        $user = $role instanceof User ? $role : $this->staff($role);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    /** An order with one line per given title. */
    protected function order(OrderStatus $status, array $titles = ['کمد دیواری', 'میز تحریر']): Order
    {
        $order = Order::factory()->create(['status' => $status->value]);

        foreach ($titles as $title) {
            Item::create([
                'title' => $title,
                'slug' => 'custom',
                'price' => 5000000,
                'quantity' => 2,
                'dimensions' => '120x60x75',
                'color' => 'سفید',
                'description' => 'با کشو',
                'order_id' => $order->id,
            ]);
        }

        return $order;
    }

    protected function service(): WorkOrderService
    {
        return app(WorkOrderService::class);
    }

    protected function orderStatus(Order $order): string
    {
        return Order::findOrFail($order->id)->getRawOriginal('status');
    }
}
