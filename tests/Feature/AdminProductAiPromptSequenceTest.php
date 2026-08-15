<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\AiImagePrompt;
use App\Models\AiPromptGroup;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\GeminiClient;
use App\Services\Admin\ProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductAiPromptSequenceTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function product(): Product
    {
        return Product::query()->create([
            'name' => 'Gold Ring',
            'slug' => 'gold-ring-seq',
            'price' => 1200,
            'is_published' => true,
            'stock_quantity' => 3,
        ]);
    }

    private function tinyPngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    #[Test]
    public function generate_runs_steps_sequentially_feeding_previous_output(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $seen = [];
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateImage')
            ->twice()
            ->andReturnUsing(function (array $parts) use (&$seen) {
                $seen[] = $parts[0]['text'] ?? '';

                return [
                    'mime' => 'image/png',
                    'base64' => $this->tinyPngBase64(),
                ];
            });
        $this->app->instance(GeminiClient::class, $gemini);

        $this->actingAs($this->adminUser());
        $product = $this->product();
        $source = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('source.jpg', 320, 240),
        );

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->call('generateAiImage', '', 'image/jpeg', $source->id, [
                'Extract the jewellery',
                'Change colour to rose gold',
            ])
            ->assertSet('aiGenerateError', null)
            ->assertCount('aiCandidates', 1);

        $this->assertCount(2, $seen);
        $this->assertStringContainsString('Step 1 of 2', $seen[0]);
        $this->assertStringContainsString('Extract the jewellery', $seen[0]);
        $this->assertStringContainsString('Step 2 of 2', $seen[1]);
        $this->assertStringContainsString('Change colour to rose gold', $seen[1]);
        $this->assertStringContainsString('same product photo', $seen[1]);
        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->where('is_admin_only', true)->count());
        $this->assertDatabaseHas('ai_image_prompts', ['prompt' => 'Extract the jewellery']);
        $this->assertDatabaseHas('ai_image_prompts', ['prompt' => 'Change colour to rose gold']);
    }

    #[Test]
    public function use_prompt_group_loads_sequence_into_editor(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();
        $group = AiPromptGroup::query()->create(['name' => 'Catalogue polish', 'sort_order' => 0]);
        AiImagePrompt::query()->create([
            'ai_prompt_group_id' => $group->id,
            'prompt' => 'Extract jewellery',
            'sort_order' => 0,
        ]);
        AiImagePrompt::query()->create([
            'ai_prompt_group_id' => $group->id,
            'prompt' => 'Rotate 90 degrees',
            'sort_order' => 1,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openAiGenerateModal')
            ->assertSee('Catalogue polish')
            ->assertSee('Extract jewellery')
            ->assertSeeHtml('usePromptGroup('.$group->id.')')
            ->assertSeeHtml('Instruction sequence')
            ->assertSeeHtml('addAiStep()')
            ->call('usePromptGroup', $group->id)
            ->assertDispatched('ai-prompt-steps-set')
            ->assertSet('aiPrompt', "Extract jewellery\nRotate 90 degrees");
    }
}
