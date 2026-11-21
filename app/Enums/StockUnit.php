<?php

namespace App\Enums;

enum StockUnit: string
{
    case SHEET = 'sheet';
    case PIECE = 'piece';
    case METER = 'meter';
    case SQUARE_METRE = 'square_metre';
    case KG = 'kg';
    case PACK = 'pack';

    public function label(): string
    {
        return match ($this) {
            self::SHEET => 'ورق',
            self::PIECE => 'عدد',
            self::METER => 'متر',
            self::SQUARE_METRE => 'متر مربع',
            self::KG => 'کیلوگرم',
            self::PACK => 'بسته',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $unit) => ['id' => $unit->value, 'name' => $unit->label()], self::cases());
    }
}
