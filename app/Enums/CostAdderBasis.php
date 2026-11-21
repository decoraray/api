<?php

namespace App\Enums;

/** Where an adder's cost comes from: a stock item it puts on the material list, or its own unit cost. */
enum CostAdderBasis: string
{
    case STOCK = 'stock';
    case SERVICE = 'service';

    public function label(): string
    {
        return match ($this) {
            self::STOCK => 'از انبار',
            self::SERVICE => 'خدمت',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $b) => ['id' => $b->value, 'name' => $b->label()], self::cases());
    }
}
