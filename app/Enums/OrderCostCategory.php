<?php

namespace App\Enums;

enum OrderCostCategory: string
{
    case LABOR = 'labor';
    case PACKAGING = 'packaging';
    case TRANSPORT = 'transport';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LABOR => 'دستمزد',
            self::PACKAGING => 'بسته‌بندی',
            self::TRANSPORT => 'حمل',
            self::OTHER => 'سایر',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => ['id' => $c->value, 'name' => $c->label()], self::cases());
    }
}
