<?php

namespace App\Support;

class Bangla
{
    private const DIGITS = [
        '0' => '০',
        '1' => '১',
        '2' => '২',
        '3' => '৩',
        '4' => '৪',
        '5' => '৫',
        '6' => '৬',
        '7' => '৭',
        '8' => '৮',
        '9' => '৯',
    ];

    /**
     * Replace Western digits in a string with Bangla digits (other characters unchanged).
     */
    public static function digits(string $value): string
    {
        return strtr($value, self::DIGITS);
    }

    /**
     * Whole-taka amount with thousands separators and Bangla digits (e.g. 1500 → "১,৫০০").
     */
    public static function money(float|int $amount): string
    {
        return self::digits(number_format((float) $amount, 0));
    }
}
