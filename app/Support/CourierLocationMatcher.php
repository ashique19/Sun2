<?php

namespace App\Support;

class CourierLocationMatcher
{
    /**
     * @param  iterable<int, array<string, mixed>>  $items
     * @param  list<string>  $nameKeys
     */
    public static function matchName(iterable $items, string $needle, array $nameKeys = ['name']): ?array
    {
        $needle = self::normalize($needle);

        if ($needle === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ($nameKeys as $key) {
                $candidate = self::normalize((string) ($item[$key] ?? ''));

                if ($candidate === '') {
                    continue;
                }

                if ($candidate === $needle) {
                    return $item;
                }

                $score = 0;

                if (str_contains($candidate, $needle) || str_contains($needle, $candidate)) {
                    $score = min(strlen($candidate), strlen($needle));
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $item;
                }
            }
        }

        return $best;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }
}
