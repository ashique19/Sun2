<?php

namespace App\Services\Locations;

use App\Models\Area;
use App\Models\City;
use App\Services\Storefront\AddressLocationGuesser;
use Illuminate\Support\Facades\Log;

class LocationAliasLearner
{
    /** @var list<string> */
    private const STOPWORDS = [
        'road', 'rd', 'street', 'st', 'lane', 'ln', 'avenue', 'ave', 'house', 'holding',
        'flat', 'floor', 'block', 'sector', 'plot', 'para', 'goli', 'gali', 'bazar',
        'market', 'thana', 'upazila', 'sadar', 'area', 'city', 'district', 'division',
        'bangladesh', 'bd', 'near', 'opposite', 'beside', 'behind', 'front', 'gate',
        'mosque', 'masjid', 'school', 'college', 'hospital', 'park', 'bridge',
        'delivery', 'deliver', 'please', 'customer', 'order', 'phone', 'mobile',
        'name', 'address', 'total', 'due', 'taka', 'tk',
    ];

    /**
     * Build a confirmable alias suggestion when the admin picks/corrects an area.
     *
     * @return array{
     *     area_id: int,
     *     alias: string,
     *     area_name: string,
     *     city_name: string,
     *     prompt: string
     * }|null
     */
    public function suggestAlias(
        ?string $address,
        ?int $selectedAreaId,
        ?int $guessedAreaId = null,
    ): ?array {
        if (! $selectedAreaId || trim((string) $address) === '') {
            return null;
        }

        if ($guessedAreaId && (int) $guessedAreaId === (int) $selectedAreaId) {
            return null;
        }

        $area = Area::query()->with('city:id,name,slug,aliases')->find($selectedAreaId);

        if (! $area?->city) {
            return null;
        }

        $candidates = $this->candidatePhrases($address, $area->city, $area);

        foreach ($candidates as $candidate) {
            if ($this->aliasConflictsWithOtherArea($candidate, $area)) {
                continue;
            }

            if ($this->areaAlreadyHasAlias($area, $candidate)) {
                continue;
            }

            return [
                'area_id' => $area->id,
                'alias' => $candidate,
                'area_name' => $area->name,
                'city_name' => $area->city->name,
                'prompt' => 'Add '.$candidate.' to '.$area->city->name.' > '.$area->name.' alias?',
            ];
        }

        return null;
    }

    /**
     * @return list<string> newly added aliases
     */
    public function confirmAlias(int $areaId, string $alias): array
    {
        $alias = trim($alias);

        if ($alias === '' || mb_strlen($alias) < 2) {
            return [];
        }

        $area = Area::query()->with('city:id,name')->find($areaId);

        if (! $area) {
            return [];
        }

        if ($this->aliasConflictsWithOtherArea($alias, $area)) {
            return [];
        }

        $added = $area->addAliases([$alias]);

        if ($added !== []) {
            AddressLocationGuesser::clearCache();

            Log::info('Confirmed area alias from admin prompt.', [
                'area_id' => $area->id,
                'area' => $area->name,
                'aliases' => $added,
            ]);
        }

        return $added;
    }

    /**
     * @return list<string> original-cased phrases from the address
     */
    public function candidatePhrases(string $address, City $city, Area $area): array
    {
        $originalTokens = $this->tokenizePreserveCase($address);
        $normalizedTokens = array_map(fn (string $token) => $this->normalize($token), $originalTokens);

        $skip = array_unique(array_filter([
            ...self::STOPWORDS,
            $this->normalize($city->name),
            $this->normalize($area->name),
            $this->normalize((string) $area->police_station),
            ...array_map(fn (string $alias) => $this->normalize($alias), $city->aliasList()),
            ...array_map(fn (string $alias) => $this->normalize($alias), $area->aliasList()),
            ...preg_split('/[-_]+/u', $this->normalize((string) $city->slug)) ?: [],
        ]));

        $candidates = [];

        for ($i = 0; $i < count($normalizedTokens) - 1; $i++) {
            $left = $normalizedTokens[$i];
            $right = $normalizedTokens[$i + 1];

            if ($this->isSkippableToken($left, $skip) || $this->isSkippableToken($right, $skip)) {
                continue;
            }

            if (preg_match('/^\d+$/u', $left) || preg_match('/^\d+$/u', $right)) {
                continue;
            }

            $phraseNormalized = $left.' '.$right;

            if (mb_strlen($phraseNormalized) >= 6) {
                $candidates[] = [
                    'display' => $originalTokens[$i].' '.$originalTokens[$i + 1],
                    'normalized' => $phraseNormalized,
                    'length' => mb_strlen($phraseNormalized),
                ];
            }
        }

        foreach ($normalizedTokens as $index => $token) {
            if ($this->isSkippableToken($token, $skip)) {
                continue;
            }

            if (preg_match('/^\d+$/u', $token)) {
                continue;
            }

            if (mb_strlen($token) >= 4) {
                $candidates[] = [
                    'display' => $originalTokens[$index],
                    'normalized' => $token,
                    'length' => mb_strlen($token),
                ];
            }
        }

        usort($candidates, function (array $a, array $b) {
            $aNonLatin = preg_match('/[^\p{Latin}\p{N}\s]/u', $a['display']) === 1 ? 1 : 0;
            $bNonLatin = preg_match('/[^\p{Latin}\p{N}\s]/u', $b['display']) === 1 ? 1 : 0;

            if ($aNonLatin !== $bNonLatin) {
                return $bNonLatin <=> $aNonLatin;
            }

            return $b['length'] <=> $a['length'];
        });

        $unique = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate['normalized']])) {
                continue;
            }

            $seen[$candidate['normalized']] = true;
            $unique[] = $candidate['display'];

            if (count($unique) >= 5) {
                break;
            }
        }

        return $unique;
    }

    /**
     * @return list<string>
     */
    private function tokenizePreserveCase(string $address): array
    {
        // Keep letters, numbers, and combining marks (e.g. Bangla hasant/virama).
        $text = preg_replace('/[^\p{L}\p{N}\p{M}\s]/u', ' ', $address) ?? $address;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $tokens = preg_split('/\s+/u', trim($text)) ?: [];

        return array_values(array_filter($tokens, fn (string $token) => $token !== ''));
    }

    /**
     * @param  list<string>  $skip
     */
    private function isSkippableToken(string $token, array $skip): bool
    {
        return in_array($token, $skip, true) || mb_strlen($token) < 3;
    }

    private function areaAlreadyHasAlias(Area $area, string $alias): bool
    {
        $normalized = $this->normalize($alias);

        if ($this->normalize($area->name) === $normalized) {
            return true;
        }

        foreach ($area->aliasList() as $existing) {
            if ($this->normalize($existing) === $normalized) {
                return true;
            }
        }

        return false;
    }

    private function aliasConflictsWithOtherArea(string $alias, Area $area): bool
    {
        $normalized = $this->normalize($alias);

        return Area::query()
            ->where('city_id', $area->city_id)
            ->whereKeyNot($area->id)
            ->get(['id', 'name', 'aliases'])
            ->contains(function (Area $other) use ($normalized) {
                if ($this->normalize($other->name) === $normalized) {
                    return true;
                }

                foreach ($other->aliasList() as $existing) {
                    if ($this->normalize($existing) === $normalized) {
                        return true;
                    }
                }

                return false;
            });
    }

    private function normalize(?string $text): string
    {
        $text = mb_strtolower(trim((string) $text));
        $text = preg_replace('/[^\p{L}\p{N}\p{M}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
