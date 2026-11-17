<?php

namespace App\Support;

/** Normalizes Persian/Arabic text so keyword matching is not defeated by look-alike characters. */
class PersianText
{
    private const MAP = [
        // Arabic letter variants → Persian
        'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه', 'أ' => 'ا', 'إ' => 'ا',
        // Persian and Arabic-Indic digits → ASCII
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        // zero-width non-joiner/joiner, LRM/RLM, BOM, tatweel → removed
        "\u{200C}" => '', "\u{200D}" => '', "\u{200E}" => '', "\u{200F}" => '', "\u{FEFF}" => '', 'ـ' => '',
    ];

    public static function normalize(?string $text): string
    {
        $text = mb_strtolower(strtr((string) $text, self::MAP), 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** True when the normalized text contains the normalized needle (empty needles never match). */
    public static function contains(?string $haystack, ?string $needle): bool
    {
        $needle = self::normalize($needle);

        return $needle !== '' && str_contains(self::normalize($haystack), $needle);
    }
}
