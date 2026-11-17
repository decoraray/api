<?php

namespace Tests\Unit;

use App\Support\PersianText;
use PHPUnit\Framework\TestCase;

class PersianTextTest extends TestCase
{
    public function test_arabic_letters_become_persian()
    {
        $this->assertSame('قیمت کابینت', PersianText::normalize('قيمت كابينت'));
        $this->assertSame('خانه', PersianText::normalize('خانة'));
    }

    public function test_digits_become_ascii()
    {
        $this->assertSame('120 cm 45', PersianText::normalize('۱۲۰ cm ٤٥'));
    }

    public function test_zero_width_and_tatweel_removed_and_case_folded()
    {
        $this->assertSame('میخوام price', PersianText::normalize("می\u{200C}خوام  PRICE"));
        $this->assertSame('قیمت', PersianText::normalize('قیـــمت'));
    }

    public function test_whitespace_collapsed()
    {
        $this->assertSame('a b', PersianText::normalize("  a \n\t b "));
    }

    public function test_contains_matches_across_variants()
    {
        $this->assertTrue(PersianText::contains('قيمت چنده؟', 'قیمت'));
        $this->assertTrue(PersianText::contains('Price please', 'price'));
        $this->assertFalse(PersianText::contains('سلام', 'قیمت'));
        $this->assertFalse(PersianText::contains('anything', '  '));
    }
}
