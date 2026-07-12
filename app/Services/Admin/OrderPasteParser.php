<?php

namespace App\Services\Admin;

use App\Models\Area;
use App\Models\City;
use App\Services\Storefront\AddressLocationGuesser;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OrderPasteParser
{
    public function __construct(
        private GeminiClient $gemini,
        private AddressLocationGuesser $locationGuesser,
    ) {}

    public function looksLikePasteBlock(string $text): bool
    {
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        if (str_contains($text, "\n") || preg_match('/\R/u', $text)) {
            return true;
        }

        if (preg_match('/TOTAL\s*DUE|টাকা|Tk\b/iu', $text)) {
            return true;
        }

        // Long single-line paste with phone + address-ish content
        return mb_strlen($text) >= 40
            && PhoneNumber::extractFirstBangladeshMobile($text) !== null
            && preg_match('/[,\s].*[,\s]/u', $text);
    }

    /**
     * @return array{
     *     name:?string,
     *     phone:?string,
     *     email:?string,
     *     address:?string,
     *     city:?string,
     *     area:?string,
     *     cityId:?int,
     *     areaId:?int,
     *     due_amount:?float,
     *     source: string,
     *     location_hint:?string
     * }
     */
    public function parse(string $raw): array
    {
        $raw = trim($raw);

        $parsed = $this->emptyResult();

        if ($raw === '') {
            return $parsed;
        }

        if ($this->gemini->isConfigured()) {
            try {
                $parsed = $this->merge($parsed, $this->parseWithGemini($raw));
                $parsed['source'] = 'gemini';
            } catch (\Throwable $e) {
                Log::warning('Order paste Gemini parse failed; using heuristics.', [
                    'message' => $e->getMessage(),
                ]);
                $parsed = $this->merge($parsed, $this->parseHeuristically($raw));
                $parsed['source'] = 'heuristic';
            }
        } else {
            $parsed = $this->merge($parsed, $this->parseHeuristically($raw));
            $parsed['source'] = 'heuristic';
        }

        if (! empty($parsed['phone'])) {
            $parsed['phone'] = PhoneNumber::display((string) $parsed['phone']);
        }

        [$cityId, $areaId, $hint] = $this->resolveLocation(
            address: $parsed['address'] ?? null,
            cityHint: $parsed['city'] ?? null,
            areaHint: $parsed['area'] ?? null,
        );

        $parsed['cityId'] = $cityId;
        $parsed['areaId'] = $areaId;
        $parsed['location_hint'] = $hint;

        return $parsed;
    }

    /**
     * @return array{0:?int,1:?int,2:?string}
     */
    public function resolveLocation(?string $address, ?string $cityHint = null, ?string $areaHint = null): array
    {
        // Prefer area resolution; city always comes from the matched area.
        $cityIdFromHint = $this->findCityId($cityHint);
        $areaId = $this->findAreaId($areaHint, $cityIdFromHint)
            ?? $this->findAreaId($areaHint, null);

        if ($areaId) {
            $area = Area::query()->with('city:id,name')->find($areaId);

            if ($area) {
                return [
                    $area->city_id,
                    $area->id,
                    $area->city ? $area->name.', '.$area->city->name : $area->name,
                ];
            }
        }

        $guessText = trim(implode(', ', array_filter([$areaHint, $cityHint, $address])));
        $guess = $this->locationGuesser->guess($guessText !== '' ? $guessText : $address);

        if ($guess && ! empty($guess['area_id'])) {
            return [
                $guess['city_id'],
                $guess['area_id'],
                $guess['label'],
            ];
        }

        // City-only fallback when Gemini/heuristics named a city but no area matched.
        if ($cityIdFromHint) {
            return [$cityIdFromHint, null, City::query()->find($cityIdFromHint)?->name];
        }

        return [null, null, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseWithGemini(string $raw): array
    {
        $system = <<<'PROMPT'
You extract Bangladesh e-commerce order customer details from messy pasted text.
Return ONLY JSON with keys:
name, phone, email, address, city, area, due_amount
Rules:
- phone must be Bangladesh mobile in 01XXXXXXXXX form when possible (convert Bangla digits).
- Prefer English city/area names when both Bangla and English appear.
- city/area should be best-guess locality names (e.g. Chattogram, Mirsharai).
- address is the full delivery address line(s), without name/phone/total.
- due_amount is a number if TOTAL DUE / Tk / টাকা is present, else null.
- Use null for unknown fields. Do not invent data.
PROMPT;

        $data = $this->gemini->generateJson($system, $raw);

        return [
            'name' => $this->nullableString($data['name'] ?? null),
            'phone' => $this->nullableString($data['phone'] ?? null)
                ?? PhoneNumber::extractFirstBangladeshMobile($raw),
            'email' => $this->nullableString($data['email'] ?? null),
            'address' => $this->nullableString($data['address'] ?? null),
            'city' => $this->nullableString($data['city'] ?? null),
            'area' => $this->nullableString($data['area'] ?? null),
            'due_amount' => isset($data['due_amount']) && is_numeric($data['due_amount'])
                ? (float) $data['due_amount']
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseHeuristically(string $raw): array
    {
        $phone = PhoneNumber::extractFirstBangladeshMobile($raw);
        $lines = preg_split('/\R+/u', $raw) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));

        $name = null;
        $addressLines = [];
        $due = null;

        foreach ($lines as $index => $line) {
            if (preg_match('/TOTAL\s*DUE|টাকা|\bTk\b/iu', $line)) {
                if (preg_match('/(\d[\d,]*)/', $line, $m)) {
                    $due = (float) str_replace(',', '', $m[1]);
                }

                continue;
            }

            if ($phone && str_contains(preg_replace('/\D+/', '', PhoneNumber::extractFirstBangladeshMobile($line) ?? ''), PhoneNumber::normalize($phone))) {
                continue;
            }

            if ($name === null && ! preg_match('/\d{5,}/', $line) && mb_strlen($line) <= 80) {
                $name = $line;

                continue;
            }

            $addressLines[] = $line;
        }

        $address = $addressLines !== [] ? implode(', ', $addressLines) : null;

        return [
            'name' => $name,
            'phone' => $phone,
            'email' => null,
            'address' => $address,
            'city' => null,
            'area' => null,
            'due_amount' => $due,
        ];
    }

    private function findCityId(?string $name): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $aliases = $this->cityAliases($name);

        foreach ($aliases as $alias) {
            $id = City::query()->active()
                ->where(function ($q) use ($alias) {
                    $q->where('name', $alias)
                        ->orWhere('name', 'like', $alias)
                        ->orWhere('slug', 'like', '%'.str($alias)->slug().'%');
                })
                ->value('id');

            if ($id) {
                return (int) $id;
            }
        }

        return City::query()->active()
            ->where('name', 'like', '%'.$name.'%')
            ->value('id');
    }

    private function findAreaId(?string $name, ?int $cityId): ?int
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $aliases = $this->areaAliases($name);

        $query = Area::query()->active()->when($cityId, fn ($q) => $q->where('city_id', $cityId));

        foreach ($aliases as $alias) {
            $slugNeedle = $this->slugSearchToken($alias);

            $id = (clone $query)->where('name', $alias)->value('id')
                ?? (clone $query)->whereRaw('LOWER(name) = ?', [mb_strtolower($alias)])->value('id')
                ?? (clone $query)->where('name', 'like', $alias.'%')->value('id')
                ?? (clone $query)->where('name', 'like', '%'.$alias.'%')->value('id')
                ?? $this->findAreaIdByJsonAlias(clone $query, $alias)
                ?? ($slugNeedle !== ''
                    ? ((clone $query)->where('slug', $slugNeedle)->value('id')
                        ?? (clone $query)->where('slug', 'like', '%-'.$slugNeedle)->value('id')
                        ?? (clone $query)->where('slug', 'like', $slugNeedle.'-%')->value('id')
                        ?? (clone $query)->where('slug', 'like', '%-'.$slugNeedle.'-%')->value('id'))
                    : null);

            if ($id) {
                return (int) $id;
            }
        }

        // Case-insensitive alias scan within the city scope (JSON contains is exact).
        if (! Schema::hasColumn('areas', 'aliases')) {
            return null;
        }

        $needle = mb_strtolower($name);
        $match = (clone $query)->get(['id', 'aliases'])
            ->first(function (Area $area) use ($needle) {
                foreach ($area->aliasList() as $alias) {
                    if (mb_strtolower($alias) === $needle) {
                        return true;
                    }
                }

                return false;
            });

        return $match ? (int) $match->id : null;
    }

    private function findAreaIdByJsonAlias(Builder $query, string $alias): ?int
    {
        if (! Schema::hasColumn('areas', 'aliases')) {
            return null;
        }

        try {
            $id = $query->whereJsonContains('aliases', $alias)->value('id');
        } catch (Throwable) {
            return null;
        }

        return $id ? (int) $id : null;
    }

    private function slugSearchToken(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', '-', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}\-]+/u', '', $value) ?? $value;

        return trim(preg_replace('/-+/u', '-', $value) ?? $value, '-');
    }

    /**
     * @return list<string>
     */
    private function cityAliases(string $name): array
    {
        $normalized = mb_strtolower(trim($name));

        $map = [
            'chittagong' => ['Chattogram', 'Chittagong'],
            'chattogram' => ['Chattogram', 'Chittagong'],
            'ctg' => ['Chattogram'],
            'চট্টগ্রাম' => ['Chattogram'],
            'dhaka' => ['Dhaka'],
            'ঢাকা' => ['Dhaka'],
        ];

        $aliases = [$name];

        if (isset($map[$normalized])) {
            $aliases = array_merge($aliases, $map[$normalized]);
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @return list<string>
     */
    private function areaAliases(string $name): array
    {
        $normalized = mb_strtolower(trim($name));

        $map = [
            'mirshorai' => ['Mirsharai', 'Mirshorai'],
            'mirsharai' => ['Mirsharai', 'Mirshorai'],
            'মিরসরাই' => ['Mirsharai'],
        ];

        $aliases = [$name];

        if (isset($map[$normalized])) {
            $aliases = array_merge($aliases, $map[$normalized]);
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function merge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if ($value !== null && $value !== '') {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(): array
    {
        return [
            'name' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'city' => null,
            'area' => null,
            'cityId' => null,
            'areaId' => null,
            'due_amount' => null,
            'source' => 'none',
            'location_hint' => null,
        ];
    }
}
