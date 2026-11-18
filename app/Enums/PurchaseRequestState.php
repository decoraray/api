<?php

namespace App\Enums;

enum PurchaseRequestState: string
{
    case OPEN = 'open';
    case ORDERED = 'ordered';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'باز',
            self::ORDERED => 'سفارش داده شده',
            self::CANCELLED => 'لغو شده',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $s) => ['id' => $s->value, 'name' => $s->label()], self::cases());
    }
}
