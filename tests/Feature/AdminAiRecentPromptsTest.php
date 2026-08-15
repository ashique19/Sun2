<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAiRecentPrompts;
use App\Models\AiImagePrompt;
use App\Models\AiPromptGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAiRecentPromptsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function lists_ungrouped_recent_prompts_and_can_delete(): void
    {
        $this->actingAs($this->admin());

        $group = AiPromptGroup::query()->create(['name' => 'Grouped', 'sort_order' => 0]);
        AiImagePrompt::query()->create([
            'ai_prompt_group_id' => $group->id,
            'prompt' => 'Grouped step',
            'sort_order' => 0,
            'last_used_at' => now(),
        ]);
        $recent = AiImagePrompt::query()->create([
            'prompt' => 'Clean white background',
            'use_count' => 3,
            'last_used_at' => now(),
        ]);

        Livewire::test(AdminAiRecentPrompts::class)
            ->assertSee('Clean white background')
            ->assertDontSee('Grouped step')
            ->call('delete', $recent->id)
            ->assertDontSee('Clean white background');

        $this->assertDatabaseMissing('ai_image_prompts', ['id' => $recent->id]);
        $this->assertDatabaseHas('ai_image_prompts', ['prompt' => 'Grouped step']);
    }
}
