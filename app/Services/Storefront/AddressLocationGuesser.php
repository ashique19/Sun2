<?php

namespace App\Services\Storefront;

use App\Models\Area;
use Illuminate\Support\Facades\Cache;

class AddressLocationGuesser
{
    private const CACHE_KEY = 'storefront.location_guess_index.v4';

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY.':areas');
        Cache::forget(self::CACHE_KEY.':cities');
    }

    /**
     * Match areas only. City is taken from the matched area.
     *
     * @return array{city_id: int, area_id: int, label: string}|null
     */
    public function guess(?string $address): ?array
    {
        $needle = $this->normalize($address);

        if (mb_strlen($needle) < 4) {
            return null;
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
     * @param  list<array{city_id: int, area_id: int, label: string, normalized: string, length: int}>  $index
     * @return array{city_id: int, area_id: int, label: string}|null
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
     * @return array{city_id: int, area_id: int, label: string}|null
     */
    private function matchUttaraSector(string $needle): ?array
    {
        if (! str_contains($needle, 'uttara') || ! str_contains($needle, 'sector')) {
            return null;
        }

        $area = Area::query()
            ->active()
            ->whereHas('city', fn ($query) => $query->active()->where('slug', 'dhaka-dhaka'))
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
     * @return list<array{city_id: int, area_id: int, label: string, normalized: string, length: int}>
     */
    private function areaIndex(): array
    {
        return Cache::rememberForever(self::CACHE_KEY.':areas', function () {
            $rows = [];

            $areas = Area::query()
                ->active()
                ->with('city:id,name,slug')
                ->whereHas('city', fn ($query) => $query->active())
                ->get(['id', 'city_id', 'name', 'slug', 'aliases', 'police_station']);

            foreach ($areas as $area) {
                $label = $area->name.', '.$area->city->name;

                foreach ($this->areaSearchPhrases($area) as $normalized) {
                    $rows[] = [
                        'city_id' => $area->city_id,
                        'area_id' => $area->id,
                        'label' => $label,
                        'normalized' => $normalized,
                        'length' => mb_strlen($normalized),
                    ];
                }
            }

            return collect($rows)
                ->filter(fn (array $row) => $row['length'] >= 4)
                ->sortByDesc('length')
                ->values()
                ->all();
        });
    }

    /**
     * @return list<string>
     */
    private function areaSearchPhrases(Area $area): array
    {
        $phrases = [];

        foreach (array_filter([
            $area->name,
            $area->police_station,
            ...$area->aliasList(),
        ]) as $phrase) {
            $normalized = $this->normalize((string) $phrase);

            if ($normalized !== '') {
                $phrases[] = $normalized;
            }
        }

        $citySkip = array_filter([
            $this->normalize($area->city?->name),
            ...preg_split('/[-_]+/u', $this->normalize((string) ($area->city?->slug ?? ''))) ?: [],
        ]);

        foreach (preg_split('/[-_]+/u', (string) ($area->slug ?? '')) ?: [] as $token) {
            $normalized = $this->normalize($token);

            if ($normalized === '' || mb_strlen($normalized) < 4) {
                continue;
            }

            if (in_array($normalized, $citySkip, true)) {
                continue;
            }

            $phrases[] = $normalized;
        }

        return array_values(array_unique($phrases));
    }

    private function containsPhrase(string $haystack, string $phrase): bool
    {
        if ($phrase === '') {
            return false;
        }

        return preg_match(
            '/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u',
            $haystack,
        ) === 1;
    }

    private function normalize(?string $text): string
    {
        $text = mb_strtolower(trim((string) $text));
        $text = preg_replace('/[^\p{L}\p{N}\p{M}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
