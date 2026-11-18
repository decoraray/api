<?php

namespace App\Enums;

/** Workshop operations a cut part goes through after the saw. Free-text detail goes in the part's note. */
enum CuttingOp: string
{
    case CNC = 'cnc';
    case EDGE_FOLD = 'edge_fold';
    case GROOVE = 'groove';
    case DRILL = 'drill';

    public function label(): string
    {
        return match ($this) {
            self::CNC => 'CNC',
            self::EDGE_FOLD => 'لبه خم',
            self::GROOVE => 'شیار',
            self::DRILL => 'سوراخ‌کاری',
        };
    }

    public static function options(): array
    {
        return array_map(fn (self $op) => ['id' => $op->value, 'name' => $op->label()], self::cases());
    }
}
