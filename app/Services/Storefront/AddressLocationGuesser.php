<?php

namespace App\Services\Storefront;

use App\Models\Area;
use App\Models\City;
use Illuminate\Support\Facades\Cache;

class AddressLocationGuesser
{
    private const CACHE_KEY = 'storefront.location_guess_index';

    /**
     * Alternate spellings that should resolve to a city name already in the index.
     *
     * @var array<string, list<string>>
     */
    private const CITY_ALIASES = [
        'chattogram' => ['chittagong', 'ctg', 'চট্টগ্রাম'],
        'dhaka' => ['ঢাকা'],
    ];

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY.':areas');
        Cache::forget(self::CACHE_KEY.':cities');
    }

    /**
     * @return array{city_id: int, area_id: int|null, label: string}|null
     */
    public function guess(?string $address): ?array
    {
        $needle = $this->normalize($address);

        if (mb_strlen($needle) < 4) {
            return null;
        }

        // Prefer an explicit city mention so short area names in other districts
        // (e.g. Dhaka "Wari" inside "Chatteswari") cannot override the city.
        $cityMatch = $this->matchFromIndex($needle, $this->cityIndex());

        if ($cityMatch) {
            $areaInCity = $this->matchFromIndex(
                $needle,
                array_values(array_filter(
                    $this->areaIndex(),
                    fn (array $row) => $row['city_id'] === $cityMatch['city_id'],
                )),
            );

            if ($areaInCity) {
                return $areaInCity;
            }

            if ($match = $this->matchUttaraSector($needle, $cityMatch['city_id'])) {
                return $match;
            }

            return $cityMatch;
        }

        if ($match = $this->matchFromIndex($needle, $this->areaIndex())) {
            return $match;
        }

        if ($match = $this->matchUttaraSector($needle)) {
            return $match;
        }

        return null;
    }

    /**
     * @param  list<array{city_id: int, area_id: int|null, label: string, normalized: string, length: int}>  $index
     * @return array{city_id: int, area_id: int|null, label: string}|null
     */
    private function matchFromIndex(string $needle, array $index): ?array
    {
        foreach ($index as $row) {
            if ($this->containsPhrase($needle, $row['normalized'])) {
                return [
                    'city_id' => $row['city_id'],
                    'area_id' => $row['area_id'],
                    'label' => $row['label'],
                ];
            }
        }

        return null;
    }

    /**
     * @return array{city_id: int, area_id: int|null, label: string}|null
     */
    private function matchUttaraSector(string $needle, ?int $cityId = null): ?array
    {
        if (! str_contains($needle, 'uttara') || ! str_contains($needle, 'sector')) {
            return null;
        }

        $area = Area::query()
            ->active()
            ->whereHas('city', function ($query) use ($cityId) {
                $query->active()->where('slug', 'dhaka-dhaka');

                if ($cityId) {
                    $query->whereKey($cityId);
                }
            })
            ->where('name', 'like', 'Uttara%')
            ->orderBy('name')
            ->first(['id', 'city_id', 'name']);

        if (! $area) {
            return null;
        }

        return [
            'city_id' => $area->city_id,
            'area_id' => $area->id,
            'label' => $area->name.', Dhaka',
        ];
    }

    /**
     * @return list<array{city_id: int, area_id: int|null, label: string, normalized: string, length: int}>
     */
    private function areaIndex(): array
    {
        return Cache::rememberForever(self::CACHE_KEY.':areas', function () {
            return Area::query()
                ->active()
                ->with('city:id,name')
                ->whereHas('city', fn ($query) => $query->active())
                ->get(['id', 'city_id', 'name'])
                ->map(function (Area $area) {
                    $normalized = $this->normalize($area->name);

                    return [
                        'city_id' => $area->city_id,
                        'area_id' => $area->id,
                        'label' => $area->name.', '.$area->city->name,
                        'normalized' => $normalized,
                        'length' => mb_strlen($normalized),
                    ];
                })
                ->filter(fn (array $row) => $row['length'] >= 4)
                ->sortByDesc('length')
                ->values()
                ->all();
        });
    }

    /**
     * @return list<array{city_id: int, area_id: int|null, label: string, normalized: string, length: int}>
     */
    private function cityIndex(): array
    {
        return Cache::rememberForever(self::CACHE_KEY.':cities', function () {
            $rows = [];

            foreach (City::query()->active()->get(['id', 'name']) as $city) {
                $normalized = $this->normalize($city->name);

                if (mb_strlen($normalized) < 4) {
                    continue;
                }

                $rows[] = [
                    'city_id' => $city->id,
                    'area_id' => null,
                    'label' => $city->name,
                    'normalized' => $normalized,
                    'length' => mb_strlen($normalized),
                ];

                foreach (self::CITY_ALIASES[$normalized] ?? [] as $alias) {
                    $aliasNormalized = $this->normalize($alias);

                    if (mb_strlen($aliasNormalized) < 3) {
                        continue;
                    }

                    $rows[] = [
                        'city_id' => $city->id,
                        'area_id' => null,
                        'label' => $city->name,
                        'normalized' => $aliasNormalized,
                        'length' => mb_strlen($aliasNormalized),
                    ];
                }
            }

            return collect($rows)
                ->sortByDesc('length')
                ->values()
                ->all();
        });
    }

    private function containsPhrase(string $haystack, string $phrase): bool
    {
        if ($phrase === '') {
            return false;
        }

        // True Unicode letter/number boundaries (not letter-then-digit).
        return preg_match(
            '/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u',
            $haystack,
        ) === 1;
    }

    private function normalize(?string $text): string
    {
        $text = mb_strtolower(trim((string) $text));
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
