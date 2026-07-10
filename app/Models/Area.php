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
            'is_active' => 'boolean',
            'delivery_charge_upto_5' => 'integer',
            'delivery_charge_over_5' => 'integer',
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
}
