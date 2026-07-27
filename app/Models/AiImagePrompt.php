<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiImagePrompt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'use_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeRecent(Builder $query, int $limit = 12): Builder
    {
        return $query->orderByDesc('last_used_at')->orderByDesc('id')->limit($limit);
    }

    public static function remember(string $prompt, ?int $userId = null): self
    {
        $prompt = trim($prompt);

        $row = static::query()->where('prompt', $prompt)->first();

        if (! $row) {
            $row = new static([
                'prompt' => $prompt,
                'user_id' => $userId,
                'use_count' => 0,
            ]);
        } elseif ($userId && ! $row->user_id) {
            $row->user_id = $userId;
        }

        $row->use_count = (int) $row->use_count + 1;
        $row->last_used_at = now();
        $row->save();

        return $row;
    }
}
