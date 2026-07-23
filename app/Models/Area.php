<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Area extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'city_id' => 'integer',
            'is_active' => 'boolean',
            'delivery_charge_upto_5' => 'integer',
            'delivery_charge_over_5' => 'integer',
            'aliases' => 'array',
        ];
    }

    public function deliveryChargeFor(int $itemCount): float
    {
        if ($itemCount <= 0) {
            return 0;
        }

        return (float) ($itemCount <= 5
            ? $this->delivery_charge_upto_5
            : $this->delivery_charge_over_5);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return list<string>
     */
    public function aliasList(): array
    {
        $aliases = is_array($this->aliases) ? $this->aliases : [];

        return array_values(array_filter(array_map(
            fn ($alias) => trim((string) $alias),
            $aliases,
        ), fn (string $alias) => $alias !== ''));
    }

    /**
     * @param  list<string>|string  $aliases
     * @return list<string> newly added aliases
     */
    public function addAliases(array|string $aliases): array
    {
        $incoming = is_array($aliases)
            ? $aliases
            : (preg_split('/[\n,]+/u', (string) $aliases) ?: []);
        $current = $this->aliasList();
        $currentNormalized = array_map(fn (string $alias) => mb_strtolower($alias), $current);
        $added = [];

        foreach ($incoming as $alias) {
            $alias = trim((string) $alias);

            if ($alias === '') {
                continue;
            }

            $normalized = mb_strtolower($alias);

            if (in_array($normalized, $currentNormalized, true)) {
                continue;
            }

            if (mb_strtolower((string) $this->name) === $normalized) {
                continue;
            }

            $current[] = $alias;
            $currentNormalized[] = $normalized;
            $added[] = $alias;
        }

        if ($added !== []) {
            $this->aliases = array_values($current);
            $this->save();
        }

        return $added;
    }
}
