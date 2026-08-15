<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminAiPromptGroupEdit;
use App\Livewire\Admin\AdminAiPromptGroups;
use App\Models\AiImagePrompt;
use App\Models\AiPromptGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAiPromptGroupsTest extends TestCase
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
    public function admin_can_create_prompt_group_with_ordered_steps(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AdminAiPromptGroupEdit::class)
            ->set('name', 'Clean catalogue')
            ->set('description', 'Extract then recolour')
            ->set('steps', [
                ['id' => null, 'prompt' => 'Extract the jewellery'],
                ['id' => null, 'prompt' => 'Change colour to gold'],
                ['id' => null, 'prompt' => 'Rotate 90 degrees'],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Prompt group saved.');

        $group = AiPromptGroup::query()->first();
        $this->assertNotNull($group);
        $this->assertSame('Clean catalogue', $group->name);
        $this->assertSame([
            'Extract the jewellery',
            'Change colour to gold',
            'Rotate 90 degrees',
        ], $group->fresh(['prompts'])->stepTexts());
    }

    #[Test]
    public function admin_can_update_and_reorder_steps(): void
    {
        $this->actingAs($this->admin());
        $group = AiPromptGroup::query()->create([
            'name' => 'Draft',
            'sort_order' => 0,
        ]);
        $first = AiImagePrompt::query()->create([
            'ai_prompt_group_id' => $group->id,
            'prompt' => 'Step A',
            'sort_order' => 0,
        ]);
        $second = AiImagePrompt::query()->create([
            'ai_prompt_group_id' => $group->id,
            'prompt' => 'Step B',
            'sort_order' => 1,
        ]);

        Livewire::test(AdminAiPromptGroupEdit::class, ['group' => $group])
            ->call('moveStepLater', 0)
            ->set('steps.0.prompt', 'Step B updated')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([
            'Step B updated',
            'Step A',
        ], $group->fresh(['prompts'])->stepTexts());
        $this->assertDatabaseHas('ai_image_prompts', ['id' => $first->id, 'prompt' => 'Step A']);
        $this->assertDatabaseHas('ai_image_prompts', ['id' => $second->id, 'prompt' => 'Step B updated']);
    }

    #[Test]
    public function index_lists_groups_and_delete_removes_steps(): void
    {
        $this->actingAs($this->admin());
        $group = AiPromptGroup::query()->create(['name' => 'Listed group', 'sort_order' => 1]);
        AiImagePrompt::query()->create([
            'ai_prompt_group_id' => $group->id,
            'prompt' => 'Do something',
            'sort_order' => 0,
        ]);

        Livewire::test(AdminAiPromptGroups::class)
            ->assertSee('Listed group')
            ->assertSee('Do something')
            ->call('delete', $group->id)
            ->assertDontSee('Listed group');

        $this->assertDatabaseMissing('ai_prompt_groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('ai_image_prompts', ['prompt' => 'Do something']);
    }
}
