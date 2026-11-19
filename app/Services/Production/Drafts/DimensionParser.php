<?php

namespace App\Services\Production\Drafts;

/**
 * Reads a product's free-text overall dimensions («120*45*80», «طول 120 * عمق 45 * ارتفاع 80», «۷۰×۳۵×۱۷۰ سانتی‌متر»)
 * into width, height and depth in cm. Labelled values are read by label; otherwise the template's axis rule assigns
 * them and the reading is marked as guessed. Anything else (a set with two sizes, «استاندارد», a price list) is
 * unreadable and returns null.
 */
class DimensionParser
{
    public const MIN_CM = 5;
    public const MAX_CM = 400;

    private const NUMBER = '\d+(?:\.\d+)?';

    public function parse(?string $text, AxisRule $rule): ?DimensionReading
    {
        $text = $this->normalise((string) $text);
        if ($text === '' || $this->isSet($text)) {
            return null;
        }

        $reading = $this->labelled($text, $rule) ?? $this->unlabelled($text, $rule);
        if (!$reading) {
            return null;
        }

        foreach ($reading->toArray() as $value) {
            if ($value !== null && ($value < self::MIN_CM || $value > self::MAX_CM)) {
                return null;
            }
        }

        return $reading;
    }

    /** Latin digits and decimal point, no units in parentheses, collapsed spaces. */
    private function normalise(string $text): string
    {
        $text = strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', 'ي' => 'ی', 'ك' => 'ک',
        ]);
        $text = preg_replace('/\([^)]*\)/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** Two or more groups of numbers separated by «/» or a new line: a set or a price list, not one piece. */
    private function isSet(string $text): bool
    {
        $groups = array_filter(preg_split('/[\/\n]/u', $text), fn ($part) => preg_match_all('/' . self::NUMBER . '/', $part) >= 2);

        return count($groups) >= 2;
    }

    private function labelled(string $text, AxisRule $rule): ?DimensionReading
    {
        $found = [];
        preg_match_all('/(طول|عرض|عمق|ارتفاع)\s*:?\s*(' . self::NUMBER . ')/u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $label, $value]) {
            $found[$label] = (float) $value;
        }
        if (!$found) {
            return null;
        }

        // With طول given, عرض is the depth (طول ۱۲۰ عرض ۴۵); alone it is the width.
        $width = $found['طول'] ?? ($found['عرض'] ?? null);
        $depth = $found['عمق'] ?? (isset($found['طول']) ? ($found['عرض'] ?? null) : null);
        $height = $found['ارتفاع'] ?? null;

        $values = ['width' => $width, 'height' => $height, 'depth' => $depth];
        $needed = match ($rule) {
            AxisRule::BED => ['width', 'depth'],
            AxisRule::PANEL => ['width', 'height'],
            default => ['width', 'height', 'depth'],
        };
        foreach ($needed as $axis) {
            if ($values[$axis] === null) {
                return null;
            }
        }

        return new DimensionReading($width, $height, $depth, false);
    }

    private function unlabelled(string $text, AxisRule $rule): ?DimensionReading
    {
        preg_match_all('/' . self::NUMBER . '/', $text, $matches);
        $numbers = array_map('floatval', $matches[0]);

        // A bed or mirror is sometimes written with its height too: drop the smallest number.
        if ($rule->size() === 2 && count($numbers) === 3) {
            rsort($numbers);
            array_pop($numbers);
        }
        if (count($numbers) !== $rule->size()) {
            return null;
        }

        $axes = $rule->assign($numbers);

        return new DimensionReading($axes['width'], $axes['height'], $axes['depth'], true);
    }
}
