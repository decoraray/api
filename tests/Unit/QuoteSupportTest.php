<?php

namespace Tests\Unit;

use App\Enums\PaymentMethods;
use App\Services\Quotes\QuoteTotals;
use App\Support\Phone;
use PHPUnit\Framework\TestCase;

class QuoteSupportTest extends TestCase
{
    public function test_on_delivery_shipping_is_zero()
    {
        $t = QuoteTotals::compute(10000000, 'on_delivery', 500000, 0, 60);
        $this->assertSame(0, $t['shipping']);
        $this->assertSame(10000000, $t['payable']);
    }

    public function test_shipping_and_discount()
    {
        $t = QuoteTotals::compute(10000000, 'amount', 500000, 1000000, 60);
        $this->assertSame(9500000, $t['payable']);
    }

    public function test_deposit_and_rounding()
    {
        $this->assertSame(21600000, QuoteTotals::compute(36000000, 'amount', 0, 0, 60)['deposit']);
        // 1,234,567 * 60% = 740,740.2 -> 741,000
        $t = QuoteTotals::compute(1234567, 'amount', 0, 0, 60, 100000);
        $this->assertSame(741000, $t['deposit']);
        $this->assertSame(1134567, $t['remaining']);
    }

    public function test_phone_normalize()
    {
        $this->assertSame('09123456789', Phone::normalize('۰۹۱۲۳۴۵۶۷۸۹'));
        $this->assertSame('09123456789', Phone::normalize('٠٩١٢٣٤٥٦٧٨٩'));
        $this->assertSame('09123456789', Phone::normalize('+989123456789'));
        $this->assertSame('09123456789', Phone::normalize('00989123456789'));
        $this->assertSame('09123456789', Phone::normalize('9123456789'));
        $this->assertSame('09123456789', Phone::normalize('989123456789'));
        $this->assertSame('09123456789', Phone::normalize('0912 345-6789'));
    }

    public function test_from_name_returns_cases()
    {
        foreach (['ONLINE', 'CASH', 'PARTIAL', 'CARD', 'CHEQUE', 'OTHER'] as $name) {
            $this->assertSame($name, PaymentMethods::fromName(strtolower($name))->name);
        }
        $this->assertSame('کارت به کارت', PaymentMethods::CARD->value);
        $this->assertSame('چک', PaymentMethods::CHEQUE->value);
        $this->assertSame('سایر', PaymentMethods::OTHER->value);
    }
}
