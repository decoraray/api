<?php

namespace App\Enums;

enum CustomerType: string
{
    case CONSUMER = 'consumer';
    case PARTNER = 'partner';
    case WHOLESALE = 'wholesale';

    public function label(): string
    {
        return match ($this) {
            self::CONSUMER => 'مصرف‌کننده',
            self::PARTNER => 'همکار',
            self::WHOLESALE => 'عمده',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['id' => $c->value, 'label' => $c->label()], self::cases());
    }
}
