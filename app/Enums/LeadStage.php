<?php

namespace App\Enums;

enum LeadStage: string
{
    case NEW = 'new';
    case CONTACTED = 'contacted';
    case QUOTE_SENT = 'quote_sent';
    case WON = 'won';
    case LOST = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'جدید',
            self::CONTACTED => 'تماس گرفته شد',
            self::QUOTE_SENT => 'پیش‌فاکتور ارسال شد',
            self::WON => 'موفق',
            self::LOST => 'از دست رفته',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::WON || $this === self::LOST;
    }

    public static function options(): array
    {
        return array_map(fn (self $c) => ['id' => $c->value, 'label' => $c->label()], self::cases());
    }
}
