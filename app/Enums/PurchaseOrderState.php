<?php

namespace App\Enums;

enum PurchaseOrderState: string
{
    case DRAFT = 'draft';
    case PLACED = 'placed';
    case PARTIALLY_RECEIVED = 'partially_received';
    case RECEIVED = 'received';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'پیش‌نویس',
            self::PLACED => 'سفارش داده شده',
            self::PARTIALLY_RECEIVED => 'دریافت ناقص',
            self::RECEIVED => 'دریافت شده',
            self::CLOSED => 'بسته شده',
            self::CANCELLED => 'لغو شده',
        };
    }

    /** States in which goods can still arrive. */
    public static function receivable(): array
    {
        return [self::PLACED, self::PARTIALLY_RECEIVED];
    }

    public static function values(): array
    {
        return array_map(fn (self $s) => ['id' => $s->value, 'name' => $s->label()], self::cases());
    }
}
