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
    ];

    public function __construct(
        private AddressLocationGuesser $guesser,
    ) {}

    /**
     * Learn aliases when an admin finalizes city/area for an address that the
     * auto-guesser missed or mismatched.
     *
     * @return array{area: list<string>, city: list<string>}
     */
    public function learnFromCorrection(
        ?string $address,
        ?int $selectedCityId,
        ?int $selectedAreaId,
        ?int $guessedCityId = null,
        ?int $guessedAreaId = null,
    ): array {
        $learned = ['area' => [], 'city' => []];

        if (! $selectedCityId || trim((string) $address) === '') {
            return $learned;
        }

        $guess = $this->guesser->guess($address);
        $effectiveGuessedCityId = $guessedCityId ?? $guess['city_id'] ?? null;
        $effectiveGuessedAreaId = $guessedAreaId ?? $guess['area_id'] ?? null;

        $city = City::query()->find($selectedCityId);
        $area = $selectedAreaId
            ? Area::query()->whereKey($selectedAreaId)->where('city_id', $selectedCityId)->first()
            : null;

        if (! $city) {
            return $learned;
        }

        // City was wrong or missing → learn city aliases from address tokens that
        // look like the selected city name variants (handled lightly via leftover phrases).
        if ($effectiveGuessedCityId && (int) $effectiveGuessedCityId !== (int) $selectedCityId) {
            // Do not auto-learn city aliases aggressively; city names are short and risky.
        }

        if (! $area) {
            return $learned;
        }

        // Only learn when the guesser did not already land on this area.
        if ($effectiveGuessedAreaId && (int) $effectiveGuessedAreaId === (int) $area->id) {
            return $learned;
        }

        $candidates = $this->candidatePhrases($address, $city, $area);

        if ($candidates === []) {
            return $learned;
        }

        $safe = [];

        foreach ($candidates as $candidate) {
            if ($this->aliasConflictsWithOtherArea($candidate, $area)) {
                continue;
            }

            $safe[] = $candidate;
        }

        if ($safe === []) {
            return $learned;
        }

        $learned['area'] = $area->addAliases($safe);

        if ($learned['area'] !== []) {
            AddressLocationGuesser::clearCache();

            Log::info('Learned area aliases from admin correction.', [
                'area_id' => $area->id,
                'area' => $area->name,
                'aliases' => $learned['area'],
                'address' => $address,
            ]);
        }

        return $learned;
    }

    /**
     * @return list<string>
     */
    public function candidatePhrases(string $address, City $city, Area $area): array
    {
        $normalized = $this->normalize($address);
        $tokens = preg_split('/\s+/u', $normalized) ?: [];
        $tokens = array_values(array_filter($tokens, fn (string $token) => $token !== ''));

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

        // Prefer 2-token phrases, then strong single tokens.
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $left = $tokens[$i];
            $right = $tokens[$i + 1];

            if ($this->isSkippableToken($left, $skip) || $this->isSkippableToken($right, $skip)) {
                continue;
            }

            if (preg_match('/^\d+$/u', $left) || preg_match('/^\d+$/u', $right)) {
                continue;
            }

            $phrase = $left.' '.$right;

            if (mb_strlen($phrase) >= 7) {
                $candidates[] = $phrase;
            }
        }

        foreach ($tokens as $token) {
            if ($this->isSkippableToken($token, $skip)) {
                continue;
            }

            if (preg_match('/^\d+$/u', $token)) {
                continue;
            }

            if (mb_strlen($token) >= 5) {
                $candidates[] = $token;
            }
        }

        // Keep longest / most specific first, unique.
        usort($candidates, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $unique = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate])) {
                continue;
            }

            $seen[$candidate] = true;
            $unique[] = $candidate;

            if (count($unique) >= 3) {
                break;
            }
        }

        return $unique;
    }

    /**
     * @param  list<string>  $skip
     */
    private function isSkippableToken(string $token, array $skip): bool
    {
        return in_array($token, $skip, true) || mb_strlen($token) < 4;
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
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
