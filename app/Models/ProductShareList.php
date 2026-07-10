<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductShareList extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function removeItem(string $key): void
    {
        $items = collect($this->items ?? [])
            ->reject(fn (array $item) => ($item['key'] ?? '') === $key)
            ->values()
            ->all();

        $this->update(['items' => $items]);
    }
}
