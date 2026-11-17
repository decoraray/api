<?php

namespace App\Services\Inventory;

use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of stock. Every change is a ledger row; the item's quantity is the ledger sum,
 * kept on the item under a row lock so two movements at once cannot lose one another.
 */
class InventoryService
{
    /** Stock arrives. A unit cost moves the item's average; without one the receipt is valued at the average. */
    public function receive(StockItem $item, float $quantity, ?int $unitCost, ?User $user, ?string $note = null, ?Model $reference = null): StockMovement
    {
        return $this->record($item, StockMovement::TYPE_IN, function (StockItem $locked) use ($quantity, $unitCost) {
            $cost = $unitCost ?? $locked->average_cost;
            $total = $locked->quantity + $quantity;
            $average = $total > 0
                ? (int) round(($locked->quantity * $locked->average_cost + $quantity * $cost) / $total)
                : $locked->average_cost;

            return [$quantity, $cost, $average];
        }, $user, $note, $reference);
    }

    /** Stock leaves, valued at the current average. */
    public function issue(StockItem $item, float $quantity, ?User $user, ?string $note = null, ?Model $reference = null): StockMovement
    {
        return $this->record($item, StockMovement::TYPE_OUT, fn (StockItem $locked) => [-$quantity, $locked->average_cost, $locked->average_cost], $user, $note, $reference);
    }

    /** A count found a different quantity: the ledger gets the difference. */
    public function adjust(StockItem $item, float $countedQuantity, ?User $user, ?string $note = null): StockMovement
    {
        return $this->record($item, StockMovement::TYPE_ADJUST, function (StockItem $locked) use ($countedQuantity) {
            $delta = round($countedQuantity - $locked->quantity, 3);
            if ($delta == 0.0) {
                throw ValidationException::withMessages(['quantity' => ['موجودی شمارش‌شده با موجودی ثبت‌شده برابر است.']]);
            }

            return [$delta, $locked->average_cost, $locked->average_cost];
        }, $user, $note);
    }

    /**
     * @param callable(StockItem): array{0: float, 1: int, 2: int} $change signed quantity, unit cost of the movement, new average
     */
    private function record(StockItem $item, string $type, callable $change, ?User $user, ?string $note, ?Model $reference = null): StockMovement
    {
        return DB::transaction(function () use ($item, $type, $change, $user, $note, $reference) {
            $locked = StockItem::lockForUpdate()->findOrFail($item->id);

            [$delta, $unitCost, $average] = $change($locked);
            $balance = round($locked->quantity + $delta, 3);

            if ($balance < 0) {
                throw ValidationException::withMessages(['quantity' => ['موجودی کافی نیست.']]);
            }

            $movement = StockMovement::create([
                'stock_item_id' => $locked->id,
                'type' => $type,
                'quantity' => round($delta, 3),
                'unit_cost' => $unitCost,
                'balance_after' => $balance,
                'note' => $note ?: null,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'user_id' => $user?->id,
                'created_at' => now(),
            ]);

            // Not fillable on purpose; the ledger is the only way in.
            $locked->forceFill(['quantity' => $balance, 'average_cost' => $average])->saveQuietly();
            $item->setRawAttributes($locked->getAttributes(), true);

            return $movement;
        });
    }
}
