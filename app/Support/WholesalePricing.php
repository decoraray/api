<?php

namespace App\Support;

use App\Services\Settings;

class WholesalePricing
{
    public const SETTING_KEY = 'wholesale_discount_percent';
    public const DEFAULT_PERCENT = 15;

    public static function percent(): int
    {
        $value = app(Settings::class)->get(self::SETTING_KEY);

        return $value === null ? self::DEFAULT_PERCENT : (int) $value;
    }

    /** Retail price minus the default percentage, rounded to the nearest 1,000. */
    public static function fallback(int $retail, ?int $percent = null): int
    {
        $percent ??= self::percent();

        return (int) round($retail * (1 - $percent / 100), -3);
    }
}
