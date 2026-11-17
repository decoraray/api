<?php

namespace App\Enums;

enum WorkOrderState: string
{
    case WAITING = 'waiting';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::WAITING => 'در انتظار',
            self::IN_PROGRESS => 'در حال تولید',
            self::DONE => 'تمام شده',
            self::CANCELLED => 'لغو شده',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $state) => ['id' => $state->value, 'name' => $state->label()], self::cases());
    }
}
