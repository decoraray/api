<?php

namespace App\Services\Costing;

use App\Services\Settings;

/**
 * The suggested sale price of a design: its estimated cost times the price multiplier, rounded up to the rounding
 * step. Never stored, so it follows today's average costs and settings. Only for users with `costs.view`; callers check.
 */
class SuggestedPrice
{
    public const MULTIPLIER_KEY = 'costing.price_multiplier';
    public const ROUNDING_KEY = 'costing.price_rounding';
    public const DEFAULT_MULTIPLIER = 2.1;
    public const DEFAULT_ROUNDING = 10000;
    public const ROUNDING_STEPS = [1000, 10000, 50000, 100000];

    public function __construct(private Settings $settings)
    {
    }

    public function multiplier(): float
    {
        return round((float) $this->settings->get(self::MULTIPLIER_KEY, self::DEFAULT_MULTIPLIER), 2);
    }

    public function rounding(): int
    {
        $step = (int) $this->settings->get(self::ROUNDING_KEY, self::DEFAULT_ROUNDING);

        return in_array($step, self::ROUNDING_STEPS, true) ? $step : self::DEFAULT_ROUNDING;
    }

    /** @return array{price_multiplier: float, price_rounding: int} */
    public function settings(): array
    {
        return ['price_multiplier' => $this->multiplier(), 'price_rounding' => $this->rounding()];
    }

    /** cost × multiplier rounded up to the step, in integer arithmetic (the multiplier as hundredths); null for no cost. */
    public function for(int $cost, ?float $multiplier = null, ?int $rounding = null): ?int
    {
        if ($cost <= 0) {
            return null;
        }
        $hundredths = (int) round(($multiplier ?? $this->multiplier()) * 100);
        $unit = 100 * ($rounding ?? $this->rounding());

        return intdiv($cost * $hundredths + $unit - 1, $unit) * ($rounding ?? $this->rounding());
    }

    /** (price − cost) ÷ price as a percentage with one decimal; null when there is no price. */
    public static function margin(?int $price, int $cost): ?float
    {
        return $price ? round(($price - $cost) * 100 / $price, 1) : null;
    }
}
