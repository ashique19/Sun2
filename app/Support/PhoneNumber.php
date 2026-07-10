<?php

namespace App\Support;

class PhoneNumber
{
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', self::toAsciiDigits($phone)) ?? '';

        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 3);
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    public static function display(string $phone): string
    {
        $normalized = self::normalize($phone);

        if (strlen($normalized) === 10) {
            return '0'.$normalized;
        }

        return $phone;
    }

    public static function isValidBangladeshMobile(string $phone): bool
    {
        $normalized = self::normalize($phone);

        return (bool) preg_match('/^1[3-9]\d{8}$/', $normalized);
    }

    public static function isValidDisplayMobile(string $phone): bool
    {
        $display = self::display($phone);

        return (bool) preg_match('/^01[3-9]\d{8}$/', $display);
    }

    public static function extractFirstBangladeshMobile(string $text): ?string
    {
        $digits = preg_replace('/\D+/', '', self::toAsciiDigits($text)) ?? '';

        if (preg_match('/880(1[3-9]\d{8})/', $digits, $matches)) {
            return '0'.$matches[1];
        }

        if (preg_match('/(1[3-9]\d{8})/', $digits, $matches)) {
            return '0'.$matches[1];
        }

        if (preg_match('/(01[3-9]\d{8})/', $digits, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function matchCandidates(string $phone): array
    {
        $extracted = self::extractFirstBangladeshMobile($phone) ?? $phone;
        $display = self::display($extracted);
        $normalized = self::normalize($display);

        return array_values(array_unique(array_filter([
            trim($phone),
            $extracted,
            $display,
            $normalized,
            '0'.$normalized,
            '88'.$normalized,
            '+88'.$normalized,
        ])));
    }

    private static function toAsciiDigits(string $value): string
    {
        $bengali = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
        $ascii = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($bengali, $ascii, $value);
    }
}
