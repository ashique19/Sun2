<?php

namespace App\Livewire\Admin;

use App\Models\AiImagePrompt;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Recent AI Prompts')]
#[Layout('components.layouts.admin')]
class AdminAiRecentPrompts extends Component
{
    public ?string $message = null;

    public function delete(int $promptId): void
    {
        AiImagePrompt::query()
            ->ungrouped()
            ->whereKey($promptId)
            ->delete();

        $this->message = 'Prompt removed.';
    }

    public function render()
    {
        return view('livewire.admin.admin-ai-recent-prompts', [
            'prompts' => AiImagePrompt::query()
                ->ungrouped()
                ->whereNotNull('last_used_at')
                ->orderByDesc('last_used_at')
                ->orderByDesc('id')
                ->limit(100)
                ->get(['id', 'prompt', 'use_count', 'last_used_at']),
        ]);
    }
}
