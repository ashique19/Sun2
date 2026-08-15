<?php

namespace App\Livewire\Admin;

use App\Models\AiPromptGroup;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('AI Prompt Groups')]
#[Layout('components.layouts.admin')]
class AdminAiPromptGroups extends Component
{
    public ?string $error = null;

    public function delete(int $groupId): void
    {
        $this->error = null;

        $group = AiPromptGroup::query()->findOrFail($groupId);
        $group->prompts()->delete();
        $group->delete();
    }

    public function render()
    {
        return view('livewire.admin.admin-ai-prompt-groups', [
            'groups' => AiPromptGroup::query()
                ->with(['prompts' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
                ->withCount('prompts')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
