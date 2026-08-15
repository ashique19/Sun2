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
    public function generate_saves_an_image_for_each_sequence_step(): void
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

        $component = Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->call('generateAiImage', '', 'image/jpeg', $source->id, [
                'Extract the jewellery',
                'Change colour to rose gold',
            ])
            ->assertSet('aiGenerateError', null)
            ->assertCount('aiCandidates', 2);

        $candidates = $component->get('aiCandidates');
        $this->assertSame(0, $candidates[0]['step_index']);
        $this->assertSame(1, $candidates[1]['step_index']);
        $this->assertSame($candidates[0]['sequence_id'], $candidates[1]['sequence_id']);
        $this->assertSame('Extract the jewellery', $candidates[0]['step_prompt']);
        $this->assertSame(2, ProductImage::query()->where('product_id', $product->id)->where('is_admin_only', true)->count());
        $this->assertCount(2, $seen);
        $this->assertStringContainsString('Step 1 of 2', $seen[0]);
        $this->assertStringContainsString('Step 2 of 2', $seen[1]);
    }

    #[Test]
    public function retry_regenerates_a_single_step_from_previous_output(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateImage')
            ->times(3)
            ->andReturn([
                'mime' => 'image/png',
                'base64' => $this->tinyPngBase64(),
            ]);
        $this->app->instance(GeminiClient::class, $gemini);

        $this->actingAs($this->adminUser());
        $product = $this->product();
        $source = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('source.jpg', 320, 240),
        );

        $component = Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->call('generateAiImage', '', 'image/jpeg', $source->id, [
                'Extract the jewellery',
                'Change colour to rose gold',
            ])
            ->assertCount('aiCandidates', 2);

        $stepTwoId = $component->get('aiCandidates')[1]['id'];
        $versionBefore = $component->get('aiCandidates')[1]['version'];

        $component->call('retryAiCandidateStep', $stepTwoId)
            ->assertSet('aiGenerateError', null)
            ->assertCount('aiCandidates', 2);

        $this->assertSame($versionBefore + 1, $component->get('aiCandidates')[1]['version']);
        $this->assertSame(2, ProductImage::query()->where('product_id', $product->id)->where('is_admin_only', true)->count());
    }

    #[Test]
    public function retry_uses_previous_step_product_image_when_private_bins_are_missing(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $capturedInputs = [];
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateImage')
            ->times(3)
            ->andReturnUsing(function (array $parts) use (&$capturedInputs) {
                $capturedInputs[] = (string) ($parts[1]['inline_data']['data'] ?? '');

                return [
                    'mime' => 'image/png',
                    'base64' => $this->tinyPngBase64(),
                ];
            });
        $this->app->instance(GeminiClient::class, $gemini);

        $admin = $this->adminUser();
        $this->actingAs($admin);
        $product = $this->product();
        $source = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('source.jpg', 320, 240),
        );

        $component = Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->call('generateAiImage', '', 'image/jpeg', $source->id, [
                'Extract the jewellery',
                'Change colour to rose gold',
            ])
            ->assertCount('aiCandidates', 2)
            ->assertSet('aiGenerateError', null);

        $candidates = $component->get('aiCandidates');
        $stepOne = $candidates[0];
        $stepTwo = $candidates[1];
        $previousProductImageId = (int) ($stepOne['product_image_id'] ?? 0);

        $this->assertGreaterThan(0, $previousProductImageId);

        $previousImage = ProductImage::query()->findOrFail($previousProductImageId);
        $previousAbsolute = public_path(ltrim(str_replace('\\', '/', (string) $previousImage->path), '/'));
        $previousBinary = file_get_contents($previousAbsolute);
        $this->assertNotFalse($previousBinary);
        $expectedInput = base64_encode($previousBinary);

        $candidateDir = storage_path('app/private/ai-candidates/'.$admin->id);
        foreach (glob($candidateDir.'/*.bin') ?: [] as $bin) {
            @unlink($bin);
        }
        foreach (glob($candidateDir.'/*.json') ?: [] as $meta) {
            @unlink($meta);
        }

        $versionBefore = (int) $stepTwo['version'];

        $component->call('retryAiCandidateStep', $stepTwo['id'], '', 'image/jpeg', null)
            ->assertSet('aiGenerateError', null)
            ->assertCount('aiCandidates', 2);

        $this->assertSame($versionBefore + 1, $component->get('aiCandidates')[1]['version']);
        $this->assertCount(3, $capturedInputs);
        $this->assertSame($expectedInput, $capturedInputs[2]);
    }

    #[Test]
    public function retry_fails_clearly_when_previous_step_image_is_unavailable(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateImage')
            ->twice()
            ->andReturn([
                'mime' => 'image/png',
                'base64' => $this->tinyPngBase64(),
            ]);
        $this->app->instance(GeminiClient::class, $gemini);

        $admin = $this->adminUser();
        $this->actingAs($admin);
        $product = $this->product();
        $source = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('source.jpg', 320, 240),
        );

        $component = Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->call('generateAiImage', '', 'image/jpeg', $source->id, [
                'Extract the jewellery',
                'Change colour to rose gold',
            ])
            ->assertCount('aiCandidates', 2);

        $candidates = $component->get('aiCandidates');
        $stepOneId = $candidates[0]['id'];
        $stepTwoId = $candidates[1]['id'];
        $previousProductImageId = (int) ($candidates[0]['product_image_id'] ?? 0);

        $candidateDir = storage_path('app/private/ai-candidates/'.$admin->id);
        foreach (glob($candidateDir.'/*.bin') ?: [] as $bin) {
            @unlink($bin);
        }

        ProductImage::query()->whereKey($previousProductImageId)->delete();

        $updated = $candidates;
        $updated[0]['product_image_id'] = null;
        $component->set('aiCandidates', $updated);

        $component->call('retryAiCandidateStep', $stepTwoId, '', 'image/jpeg', null)
            ->assertSet('aiGenerateError', 'The previous step image is no longer available. Re-run the full sequence, then retry.');

        $this->assertSame($stepOneId, $component->get('aiCandidates')[0]['id']);
    }

    #[Test]
    public function use_prompt_group_loads_sequence_and_modal_links_recent_prompts_page(): void
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
            ->assertSeeHtml('usePromptGroup('.$group->id.')')
            ->assertSeeHtml(route('admin.ai-prompts.recent'))
            ->assertSee('Recent single prompts')
            ->assertDontSeeHtml('wire:click="useRecentPrompt(')
            ->call('usePromptGroup', $group->id)
            ->assertDispatched('ai-prompt-steps-set')
            ->assertSet('aiPrompt', "Extract jewellery\nRotate 90 degrees");
    }
}
