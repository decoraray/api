<?php

namespace Tests\Feature\Inventory;

use App\Models\StockItem;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class InventoryTestCase extends TestCase
{
    use RefreshDatabase;

    protected function staff(string $role, array $extraPermissions = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        if ($extraPermissions) {
            $user->givePermissionTo($extraPermissions);
        }

        return $user;
    }

    protected function header(string|User $role): array
    {
        $user = $role instanceof User ? $role : $this->staff($role);

        return ['Authorization' => 'Bearer ' . AuthService::createToken($user)];
    }

    protected function inventory(): InventoryService
    {
        return app(InventoryService::class);
    }

    /** An item holding the given quantity at the given average cost. */
    protected function stocked(float $quantity, int $cost = 0, array $attributes = []): StockItem
    {
        $item = StockItem::factory()->create($attributes);
        if ($quantity > 0) {
            $this->inventory()->receive($item, $quantity, $cost, null);
        }

        return $item->refresh();
    }
}
