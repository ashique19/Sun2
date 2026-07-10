<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_order' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
    }

    public function hasUsesRemaining(): bool
    {
        return $this->usage_limit === null || $this->used_count < $this->usage_limit;
    }

    public function summaryLabel(): string
    {
        if ($this->type === 'percent') {
            $value = rtrim(rtrim(number_format((float) $this->value, 2, '.', ''), '0'), '.');

            return $value.'% off';
        }

        return '৳'.number_format((float) $this->value, 0).' off';
    }
}
