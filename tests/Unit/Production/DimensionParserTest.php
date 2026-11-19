<?php

namespace Tests\Unit\Production;

use App\Services\Production\Drafts\AxisRule;
use App\Services\Production\Drafts\DimensionParser;
use PHPUnit\Framework\TestCase;

class DimensionParserTest extends TestCase
{
    private function read(?string $text, AxisRule $rule = AxisRule::TALL): ?array
    {
        $reading = (new DimensionParser())->parse($text, $rule);

        return $reading ? $reading->toArray() + ['guessed' => $reading->guessed] : null;
    }

    public function test_labelled_values_are_read_by_label_and_not_guessed()
    {
        $this->assertSame(
            ['width' => 120.0, 'height' => 80.0, 'depth' => 45.0, 'guessed' => false],
            $this->read('طول 120 * عمق 45* ارتفاع 80', AxisRule::LOW),
        );
        // With طول given, عرض is the depth.
        $this->assertSame(
            ['width' => 120.0, 'height' => 75.0, 'depth' => 60.0, 'guessed' => false],
            $this->read('طول ۱۲۰ عرض ۶۰ ارتفاع ۷۵', AxisRule::LOW),
        );
    }

    public function test_tall_rule_takes_the_largest_as_height_and_smallest_as_depth()
    {
        $this->assertSame(['width' => 120.0, 'height' => 195.0, 'depth' => 40.0, 'guessed' => true], $this->read('120*195*40'));
    }

    public function test_low_and_table_rules()
    {
        $this->assertSame(['width' => 160.0, 'height' => 50.0, 'depth' => 40.0, 'guessed' => true], $this->read('160*50*40', AxisRule::LOW));
        $this->assertSame(['width' => 100.0, 'height' => 40.0, 'depth' => 60.0, 'guessed' => true], $this->read('40*60*100', AxisRule::TABLE));
    }

    public function test_separators_persian_digits_and_trailing_units()
    {
        // TALL: height 170, depth the smallest (35), width the middle (70).
        $expected = ['width' => 70.0, 'height' => 170.0, 'depth' => 35.0, 'guessed' => true];
        $this->assertSame($expected, $this->read('70*35*170'));
        $this->assertSame($expected, $this->read('۷۰×۳۵×۱۷۰'));
        $this->assertSame($expected, $this->read('70x35x170 سانتی‌متر'));
        $this->assertSame($expected, $this->read('70*35×170'));
        $this->assertSame(
            ['width' => 140.0, 'height' => 90.0, 'depth' => 60.0, 'guessed' => true],
            $this->read('140*60*90 سانتی‌متر (قابل تغییر)', AxisRule::LOW),
        );
        $this->assertSame(['width' => 120.0, 'height' => 75.0, 'depth' => 60.0, 'guessed' => true], $this->read('120*60 *75', AxisRule::LOW));
    }

    public function test_two_number_beds_and_mirrors()
    {
        $this->assertSame(['width' => 90.0, 'height' => null, 'depth' => 200.0, 'guessed' => true], $this->read('200*90', AxisRule::BED));
        $this->assertSame(['width' => 160.0, 'height' => null, 'depth' => 200.0, 'guessed' => true], $this->read('160*200', AxisRule::BED));
        $this->assertSame(['width' => 60.0, 'height' => 180.0, 'depth' => null, 'guessed' => true], $this->read('180*60', AxisRule::PANEL));
        // Three numbers on a bed: the smallest (a height) is dropped.
        $this->assertSame(['width' => 90.0, 'height' => null, 'depth' => 200.0, 'guessed' => true], $this->read('200*90*40', AxisRule::BED));
    }

    public function test_unreadable_text()
    {
        $this->assertNull($this->read(null));
        $this->assertNull($this->read(''));
        $this->assertNull($this->read('استاندارد'));
        $this->assertNull($this->read('100', AxisRule::LOW));
        $this->assertNull($this->read('160*45*40' . "\n" . '100*60*45', AxisRule::LOW));
        $this->assertNull($this->read('میزتلویزیون 160*40*42 / کنسول 150*40*90', AxisRule::LOW));
        $this->assertNull($this->read('ميز 4 نفره: 8 میلیون / ميز 8 نفره: 11،800 ت  /  160*110*90', AxisRule::TABLE));
        // Four numbers are never one piece's overall size.
        $this->assertNull($this->read('10*20*30*40'));
    }

    public function test_out_of_range_numbers_are_unreadable()
    {
        $this->assertNull($this->read('2000*400*400'));
        $this->assertNull($this->read('120*3*80', AxisRule::LOW));
    }
}
