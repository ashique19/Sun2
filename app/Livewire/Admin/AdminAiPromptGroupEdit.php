<?php

namespace App\Livewire\Admin;

use App\Models\AiImagePrompt;
use App\Models\AiPromptGroup;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class AdminAiPromptGroupEdit extends Component
{
    public ?AiPromptGroup $group = null;

    public string $name = '';

    public string $description = '';

    public string $sort_order = '0';

    /** @var list<array{id: int|null, prompt: string}> */
    public array $steps = [
        ['id' => null, 'prompt' => ''],
    ];

    public ?string $message = null;

    public ?string $error = null;

    public function mount(?AiPromptGroup $group = null): void
    {
        if ($group?->exists) {
            $this->group = $group->load(['prompts' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);
            $this->name = $group->name;
            $this->description = (string) ($group->description ?? '');
            $this->sort_order = (string) $group->sort_order;
            $this->steps = $group->prompts
                ->map(fn (AiImagePrompt $prompt) => [
                    'id' => $prompt->id,
                    'prompt' => $prompt->prompt,
                ])
                ->values()
                ->all();

            if ($this->steps === []) {
                $this->steps = [['id' => null, 'prompt' => '']];
            }
        }
    }

    public function title(): string
    {
        return $this->group ? 'Edit '.$this->group->name : 'Create AI Prompt Group';
    }

    public function addStep(): void
    {
        $this->steps[] = ['id' => null, 'prompt' => ''];
    }

    public function removeStep(int $index): void
    {
        if (count($this->steps) <= 1) {
            $this->steps = [['id' => null, 'prompt' => '']];

            return;
        }

        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function moveStepEarlier(int $index): void
    {
        if ($index < 1 || ! isset($this->steps[$index], $this->steps[$index - 1])) {
            return;
        }

        [$this->steps[$index - 1], $this->steps[$index]] = [$this->steps[$index], $this->steps[$index - 1]];
        $this->steps = array_values($this->steps);
    }

    public function moveStepLater(int $index): void
    {
        if (! isset($this->steps[$index], $this->steps[$index + 1])) {
            return;
        }

        [$this->steps[$index], $this->steps[$index + 1]] = [$this->steps[$index + 1], $this->steps[$index]];
        $this->steps = array_values($this->steps);
    }

    public function save(): void
    {
        $this->message = null;
        $this->error = null;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.prompt' => ['required', 'string', 'min:3', 'max:4000'],
        ]);

        $payload = [
            'name' => $validated['name'],
            'description' => ($validated['description'] ?? '') !== '' ? $validated['description'] : null,
            'sort_order' => (int) $validated['sort_order'],
        ];

        if ($this->group) {
            $this->group->update($payload);
        } else {
            $this->group = AiPromptGroup::query()->create($payload);
        }

        $keptIds = [];

        foreach (array_values($validated['steps']) as $index => $step) {
            $promptText = trim((string) $step['prompt']);
            $existingId = $this->steps[$index]['id'] ?? null;

            if (is_numeric($existingId) && (int) $existingId > 0) {
                $prompt = AiImagePrompt::query()
                    ->where('ai_prompt_group_id', $this->group->id)
                    ->whereKey((int) $existingId)
                    ->first();

                if ($prompt) {
                    $prompt->update([
                        'prompt' => $promptText,
                        'sort_order' => $index,
                    ]);
                    $keptIds[] = $prompt->id;
                    $this->steps[$index]['id'] = $prompt->id;

                    continue;
                }
            }

            $prompt = AiImagePrompt::query()->create([
                'ai_prompt_group_id' => $this->group->id,
                'prompt' => $promptText,
                'sort_order' => $index,
                'use_count' => 0,
            ]);
            $keptIds[] = $prompt->id;
            $this->steps[$index]['id'] = $prompt->id;
        }

        AiImagePrompt::query()
            ->where('ai_prompt_group_id', $this->group->id)
            ->when($keptIds !== [], fn ($q) => $q->whereNotIn('id', $keptIds), fn ($q) => $q)
            ->delete();

        $this->group->refresh();
        $this->message = 'Prompt group saved.';
    }

    public function delete(): void
    {
        if (! $this->group) {
            return;
        }

        $this->group->prompts()->delete();
        $this->group->delete();
        $this->redirect(route('admin.ai-prompts'), navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.admin-ai-prompt-group-edit')->title($this->title());
    }
}
