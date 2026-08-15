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
    public function retry_with_empty_alpine_inputs_uses_sequence_disk_for_step_two_and_source_for_step_one(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $inputModes = [];
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateImage')
            ->times(4)
            ->andReturnUsing(function (array $parts) use (&$inputModes) {
                $inputModes[] = [
                    'instruction' => (string) ($parts[0]['text'] ?? ''),
                    'data_len' => strlen((string) ($parts[1]['inline_data']['data'] ?? '')),
                ];

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
        $stepOneId = $candidates[0]['id'];
        $stepTwoId = $candidates[1]['id'];
        $sequenceId = $candidates[0]['sequence_id'];
        $versionOneBefore = $candidates[0]['version'];
        $versionTwoBefore = $candidates[1]['version'];

        $userId = auth()->id();
        $dir = storage_path('app/private/ai-candidates/'.$userId);
        $this->assertFileExists($dir.'/'.$sequenceId.'-source.bin');
        $this->assertFileExists($dir.'/'.$stepOneId.'.bin');
        $this->assertFileExists($dir.'/'.$stepTwoId.'.bin');

        // Alpine-equivalent: empty raw base64, null sourceImageId (rely on sequence disk / previous candidate).
        $component->call('retryAiCandidateStep', $stepTwoId, '', 'image/jpeg', null)
            ->assertSet('aiGenerateError', null);

        $this->assertSame($versionTwoBefore + 1, $component->get('aiCandidates')[1]['version']);
        $this->assertSame($versionOneBefore, $component->get('aiCandidates')[0]['version']);

        $component->call('retryAiCandidateStep', $stepOneId, '', 'image/jpeg', null)
            ->assertSet('aiGenerateError', null);

        $this->assertSame($versionOneBefore + 1, $component->get('aiCandidates')[0]['version']);
        $this->assertCount(4, $inputModes);
        $this->assertStringContainsString('Step 2 of 2', $inputModes[2]['instruction']);
        $this->assertStringContainsString('Step 1 of 2', $inputModes[3]['instruction']);
        $this->assertGreaterThan(0, $inputModes[2]['data_len']);
        $this->assertGreaterThan(0, $inputModes[3]['data_len']);
    }

    #[Test]
    public function retry_fails_when_sequence_disk_missing_and_alpine_inputs_empty(): void
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

        $this->actingAs($this->adminUser());
        $product = $this->product();

        // Raw upload path: candidates store source_image_id=null (no gallery fallback).
        $component = Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->call('generateAiImage', $this->tinyPngBase64(), 'image/png', null, [
                'Extract the jewellery',
                'Change colour to rose gold',
            ])
            ->assertCount('aiCandidates', 2);

        $candidates = $component->get('aiCandidates');
        $this->assertNull($candidates[0]['source_image_id']);
        $stepTwoId = $candidates[1]['id'];
        $sequenceId = $candidates[0]['sequence_id'];
        $stepOneId = $candidates[0]['id'];
        $userId = auth()->id();
        $dir = storage_path('app/private/ai-candidates/'.$userId);

        // Simulate production miss: private sequence binaries gone; Alpine cleared raw/source selection.
        @unlink($dir.'/'.$sequenceId.'-source.bin');
        @unlink($dir.'/'.$stepOneId.'.bin');
        @unlink($dir.'/'.$stepTwoId.'.bin');

        $component->call('retryAiCandidateStep', $stepTwoId, '', 'image/jpeg', null)
            ->assertSet('aiGenerateError', 'Choose a raw photo or one of the existing product images.');
    }

    #[Test]
    public function retry_nested_version_mutation_is_visible_on_component_after_call(): void
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
            ]);

        $stepTwoId = $component->get('aiCandidates')[1]['id'];

        $component->call('retryAiCandidateStep', $stepTwoId, '', 'image/jpeg', null);

        // Touch another property to force a subsequent Livewire round-trip snapshot.
        $component->set('aiPrompt', "Extract the jewellery\nChange colour to rose gold");

        $this->assertSame(2, $component->get('aiCandidates')[1]['version']);
        $this->assertSame($stepTwoId, $component->get('aiCandidates')[1]['id']);
        $this->assertNotNull($component->get('aiCandidates')[1]['sequence_id']);
        $this->assertSame(1, $component->get('aiCandidates')[1]['step_index']);
    }

    #[Test]
    public function retry_step_two_without_previous_bin_falls_back_to_gallery_source_not_product_image(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $seenInputHashes = [];
        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateImage')
            ->times(3)
            ->andReturnUsing(function (array $parts) use (&$seenInputHashes) {
                $seenInputHashes[] = substr(hash('sha256', (string) ($parts[1]['inline_data']['data'] ?? '')), 0, 12);

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
            ->assertCount('aiCandidates', 2);

        $candidates = $component->get('aiCandidates');
        $stepOneId = $candidates[0]['id'];
        $stepTwoId = $candidates[1]['id'];
        $userId = auth()->id();
        $dir = storage_path('app/private/ai-candidates/'.$userId);

        // Keep sequence source + gallery source; only remove previous-step private bin.
        @unlink($dir.'/'.$stepOneId.'.bin');
        $this->assertFileDoesNotExist($dir.'/'.$stepOneId.'.bin');
        $this->assertFileExists($dir.'/'.$candidates[0]['sequence_id'].'-source.bin');

        $component->call('retryAiCandidateStep', $stepTwoId, '', 'image/jpeg', null)
            ->assertSet('aiGenerateError', null);

        // Generate used source then step1 output; retry should prefer previous step, but with bin
        // missing it falls back to sequence source (same pixels as original gallery), NOT step1 output.
        $this->assertCount(3, $seenInputHashes);
        $this->assertSame($seenInputHashes[0], $seenInputHashes[2], 'Step-2 retry incorrectly reused original source when previous candidate bin was missing');
        $this->assertNotSame($seenInputHashes[1], $seenInputHashes[2]);
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
