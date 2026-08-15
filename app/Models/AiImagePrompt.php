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
            'ai_prompt_group_id' => 'integer',
            'sort_order' => 'integer',
            'use_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AiPromptGroup::class, 'ai_prompt_group_id');
    }

    public function scopeRecent(Builder $query, int $limit = 12): Builder
    {
        return $query
            ->whereNull('ai_prompt_group_id')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->limit($limit);
    }

    public function scopeUngrouped(Builder $query): Builder
    {
        return $query->whereNull('ai_prompt_group_id');
    }

    public static function remember(string $prompt, ?int $userId = null): self
    {
        $prompt = trim($prompt);

        $row = static::query()
            ->whereNull('ai_prompt_group_id')
            ->where('prompt', $prompt)
            ->first();

        if (! $row) {
            $row = new static([
                'prompt' => $prompt,
                'user_id' => $userId,
                'use_count' => 0,
                'sort_order' => 0,
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
