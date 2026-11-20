<?php

namespace App\Enums;

/** What kind of extra an adder is; rails and magnets are parts kept in stock, colour and vacuum are work. */
enum CostAdderGroup: string
{
    case RAIL = 'rail';
    case MAGNET = 'magnet';
    case COLOUR = 'colour';
    case VACUUM = 'vacuum';
    case MISC = 'misc';

    public function label(): string
    {
        return match ($this) {
            self::RAIL => 'ریل',
            self::MAGNET => 'مگنت',
            self::COLOUR => 'رنگ',
            self::VACUUM => 'وکیوم',
            self::MISC => 'متفرقه',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $g) => ['id' => $g->value, 'name' => $g->label()], self::cases());
    }
}
