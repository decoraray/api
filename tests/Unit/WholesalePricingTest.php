<?php

namespace Tests\Unit;

use App\Models\Deal;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WholesalePricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_wholesale_price_wins()
    {
        $v = Variation::factory()->create(['price' => 12000000, 'wholesale_price' => 9500000]);
        $this->assertSame(9500000, $v->effectiveWholesalePrice());
    }

    public function test_fallback_uses_default_15_percent_when_setting_missing()
    {
        $v = Variation::factory()->create(['price' => 12000000, 'wholesale_price' => null]);
        $this->assertSame(10200000, $v->effectiveWholesalePrice());
    }

    public function test_fallback_rounds_to_nearest_thousand_and_uses_setting()
    {
        Setting::create(['key' => 'wholesale_discount_percent', 'value' => '20']);
        Cache::forget('settings.all');
        $v = Variation::factory()->create(['price' => 1234567, 'wholesale_price' => null]);
        $this->assertSame(988000, $v->effectiveWholesalePrice()); // 987653.6 -> 988000
    }

    public function test_deal_is_ignored()
    {
        $deal = Deal::create(['discount' => 10, 'active' => true]);
        $product = Product::factory()->create(['deal_id' => $deal->id]);
        $v = Variation::factory()->create(['product_id' => $product->id, 'price' => 12000000, 'wholesale_price' => 9500000]);
        $this->assertSame(10800000, (int) $v->fresh()->calculatedPrice);
        $this->assertSame(9500000, $v->fresh()->effectiveWholesalePrice());

        $v2 = Variation::factory()->create(['product_id' => $product->id, 'price' => 12000000, 'wholesale_price' => null]);
        $this->assertSame(10200000, $v2->fresh()->effectiveWholesalePrice());
    }
}
