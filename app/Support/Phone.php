<?php

namespace App\Support;

class Phone
{
    /**
     * Normalize an Iranian mobile number: Persian/Arabic digits become Latin,
     * spaces and dashes are stripped, and +98 / 0098 / 98 becomes 0.
     */
    public static function normalize(?string $phone): string
    {
        $phone = strtr((string) $phone, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $phone = preg_replace('/[\s\-()]+/u', '', $phone);

        if (preg_match('/^(?:\+98|0098|98)(9\d{9})$/', $phone, $m)) {
            return '0' . $m[1];
        }
        if (preg_match('/^(?:\+98|0098)(\d+)$/', $phone, $m)) {
            return '0' . $m[1];
        }

        return $phone;
    }
}
