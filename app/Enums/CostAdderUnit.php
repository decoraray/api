<?php

namespace App\Enums;

/** How a service adder is counted. A per-work-order adder is not multiplied by the work order's quantity. */
enum CostAdderUnit: string
{
    case PIECE = 'piece';
    case SQUARE_METRE = 'square_metre';
    case WORK_ORDER = 'work_order';

    public function label(): string
    {
        return match ($this) {
            self::PIECE => 'عدد',
            self::SQUARE_METRE => 'متر مربع',
            self::WORK_ORDER => 'هر دستور تولید',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $u) => ['id' => $u->value, 'name' => $u->label()], self::cases());
    }
}
