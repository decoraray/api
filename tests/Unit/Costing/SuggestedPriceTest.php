<?php

namespace Tests\Unit\Costing;

use App\Services\Costing\SuggestedPrice;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

class SuggestedPriceTest extends TestCase
{
    private function pricing(array $stored = []): SuggestedPrice
    {
        $settings = new class($stored) extends Settings {
            public function __construct(private array $stored)
            {
            }

            public function all(): array
            {
                return $this->stored;
            }
        };

        return new SuggestedPrice($settings);
    }

    public function test_defaults_and_worked_examples()
    {
        $p = $this->pricing();
        $this->assertSame(['price_multiplier' => 2.1, 'price_rounding' => 10000], $p->settings());
        $this->assertSame(7_350_000, $p->for(3_500_000));
        $this->assertSame(2_600_000, $p->for(1_234_567));
        $this->assertNull($p->for(0));
    }

    public function test_an_exact_multiple_is_not_rounded_up()
    {
        // 1,000,000 × 2.1 = 2,100,000 exactly; float arithmetic would give 2,100,000.0000000002 and round up.
        $this->assertSame(2_100_000, $this->pricing()->for(1_000_000));
    }

    public function test_each_rounding_step()
    {
        $p = $this->pricing(['costing.price_multiplier' => '2.35']);
        // 1,234,567 × 2.35 = 2,901,232.45
        $this->assertSame(
            [2_902_000, 2_910_000, 2_950_000, 3_000_000],
            array_map(fn ($step) => $p->for(1_234_567, null, $step), SuggestedPrice::ROUNDING_STEPS),
        );
    }

    public function test_stored_settings_and_an_unknown_step()
    {
        $this->assertSame(['price_multiplier' => 2.5, 'price_rounding' => 50000], $this->pricing(['costing.price_multiplier' => '2.5', 'costing.price_rounding' => '50000'])->settings());
        $this->assertSame(10000, $this->pricing(['costing.price_rounding' => '3000'])->rounding());
    }

    public function test_margin()
    {
        $this->assertSame(50.0, SuggestedPrice::margin(7_000_000, 3_500_000));
        $this->assertSame(-14.3, SuggestedPrice::margin(7_000_000, 8_000_000));
        $this->assertNull(SuggestedPrice::margin(0, 3_500_000));
        $this->assertNull(SuggestedPrice::margin(null, 3_500_000));
    }
}
