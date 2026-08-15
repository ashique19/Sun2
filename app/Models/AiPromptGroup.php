<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiPromptGroup extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function prompts(): HasMany
    {
        return $this->hasMany(AiImagePrompt::class, 'ai_prompt_group_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return list<string>
     */
    public function stepTexts(): array
    {
        return $this->prompts
            ->pluck('prompt')
            ->map(fn ($prompt) => trim((string) $prompt))
            ->filter(fn (string $prompt) => $prompt !== '')
            ->values()
            ->all();
    }
}
