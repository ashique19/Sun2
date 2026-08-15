<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\AiImagePrompt;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductAiImageGenerateTest extends TestCase
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
            'name' => 'Gold Necklace',
            'slug' => 'gold-necklace',
            'price' => 1500,
            'is_published' => true,
        ]);
    }

    private function tinyPngBase64(): string
    {
        // 1x1 PNG
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    #[Test]
    public function livewire_payload_limit_allows_compressed_ai_raw_photos(): void
    {
        $this->assertGreaterThanOrEqual(
            2 * 1024 * 1024,
            (int) config('livewire.payload.max_size'),
        );
    }

    #[Test]
    public function ai_modal_prepares_raw_photo_locally_without_livewire_temp_upload(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openAiGenerateModal')
            ->assertSet('showAiGenerateModal', true)
            ->assertSeeHtml('uploadRawPhoto($event)')
            ->assertSeeHtml('generateWithRaw()')
            ->assertSeeHtml('Preparing raw photo')
            ->assertSeeHtml('AI generation progress')
            ->assertSeeHtml('generateStatus')
            ->assertSeeHtml('role="progressbar"')
            ->assertSeeHtml(':disabled="! canGenerate()"')
            ->assertSeeHtml('Or upload a raw photo')
            ->assertSeeHtml('admin-only')
            ->assertDontSeeHtml('$wire.upload(')
            ->assertDontSeeHtml('wire:model="aiRawImage"');
    }

    #[Test]
    public function generate_requires_raw_image_and_surfaces_validation_error(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateImage')->never();
        $this->app->instance(GeminiClient::class, $gemini);

        $this->actingAs($this->adminUser());
        $product = $this->product();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openAiGenerateModal')
            ->set('aiPrompt', 'Clean white background jewelry photo')
            ->call('generateAiImage')
            ->assertHasErrors(['aiRawImage'])
            ->assertSet('aiGenerateError', null)
            ->assertCount('aiCandidates', 0);
    }

    #[Test]
    public function generate_appends_session_candidate_and_remembers_prompt(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateImage')->once()->andReturn([
            'mime' => 'image/png',
            'base64' => $this->tinyPngBase64(),
        ]);
        $this->app->instance(GeminiClient::class, $gemini);

        $this->actingAs($this->adminUser());
        $product = $this->product();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openAiGenerateModal')
            ->assertSet('showAiGenerateModal', true)
            ->set('aiPrompt', 'Clean white background jewelry photo')
            ->call('generateAiImage', $this->tinyPngBase64(), 'image/png')
            ->assertSet('aiGenerateError', null)
            ->assertHasNoErrors()
            ->assertCount('aiCandidates', 1)
            ->assertSet('message', 'AI image saved (admin only — not shown on the storefront).');

        $this->assertDatabaseHas('ai_image_prompts', [
            'prompt' => 'Clean white background jewelry photo',
        ]);

        $this->assertSame(1, AiImagePrompt::query()->count());
        $this->assertSame(1, (int) AiImagePrompt::query()->first()->use_count);
        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->where('is_admin_only', true)->count());
    }

    #[Test]
    public function generate_again_appends_another_candidate(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateImage')->twice()->andReturn([
            'mime' => 'image/png',
            'base64' => $this->tinyPngBase64(),
        ]);
        $this->app->instance(GeminiClient::class, $gemini);

        $this->actingAs($this->adminUser());
        $product = $this->product();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('aiPrompt', 'Studio lighting')
            ->call('generateAiImage', $this->tinyPngBase64(), 'image/png')
            ->call('generateAiImage', $this->tinyPngBase64(), 'image/png')
            ->assertCount('aiCandidates', 2);

        $this->assertSame(2, (int) AiImagePrompt::query()->where('prompt', 'Studio lighting')->value('use_count'));
        $this->assertSame(2, ProductImage::query()->where('product_id', $product->id)->where('is_admin_only', true)->count());
    }

    #[Test]
    public function promote_adds_gallery_image_and_removes_candidate(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();
        $id = 'candidate-1';
        $adminId = (int) auth()->id();
        $dir = storage_path('app/private/ai-candidates/'.$adminId);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$id.'.bin', base64_decode($this->tinyPngBase64(), true));
        File::put($dir.'/'.$id.'.json', json_encode(['mime' => 'image/png']));

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('aiCandidates', [[
                'id' => $id,
                'mime' => 'image/png',
                'name' => 'ai-1.png',
                'version' => 1,
            ]])
            ->call('promoteAiCandidate', $id)
            ->assertCount('aiCandidates', 0)
            ->assertSet('message', 'AI image added to product gallery.');

        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
    }

    #[Test]
    public function close_modal_clears_session_candidates(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();
        $id = 'x';
        $adminId = (int) auth()->id();
        $dir = storage_path('app/private/ai-candidates/'.$adminId);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$id.'.bin', 'bytes');
        File::put($dir.'/'.$id.'.json', json_encode(['mime' => 'image/png']));

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('showAiGenerateModal', true)
            ->set('aiPrompt', 'keep me?')
            ->set('aiCandidates', [[
                'id' => $id,
                'mime' => 'image/png',
                'name' => 'ai.png',
                'version' => 1,
            ]])
            ->call('closeAiGenerateModal')
            ->assertSet('showAiGenerateModal', false)
            ->assertCount('aiCandidates', 0);

        $this->assertFileDoesNotExist($dir.'/'.$id.'.bin');
    }

    #[Test]
    public function recent_prompts_are_ordered_latest_first(): void
    {
        AiImagePrompt::query()->create([
            'prompt' => 'Older prompt',
            'use_count' => 1,
            'last_used_at' => now()->subDay(),
        ]);
        AiImagePrompt::query()->create([
            'prompt' => 'Newer prompt',
            'use_count' => 2,
            'last_used_at' => now(),
        ]);

        $recent = AiImagePrompt::query()->recent(12)->pluck('prompt')->all();

        $this->assertSame(['Newer prompt', 'Older prompt'], $recent);
    }

    #[Test]
    public function generate_surfaces_gemini_errors_without_hanging(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateImage')->once()->andThrow(
            new \RuntimeException('Gemini image API error (503): temporary unavailable'),
        );
        $this->app->instance(GeminiClient::class, $gemini);

        $this->actingAs($this->adminUser());
        $product = $this->product();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('aiPrompt', 'Studio lighting for necklace')
            ->call('generateAiImage', $this->tinyPngBase64(), 'image/png')
            ->assertSet('aiGenerateError', 'Gemini image API error (503): temporary unavailable')
            ->assertCount('aiCandidates', 0);

        $source = file_get_contents(resource_path('js/admin-product-images.js'));
        $this->assertIsString($source);
        $this->assertStringContainsString('result.ok === false', $source);
        $this->assertStringContainsString("generateStatus = 'Image ready'", $source);
        $this->assertStringContainsString("generateStatus = 'Generation failed'", $source);
    }

    #[Test]
    public function gemini_client_truncates_verbose_http_errors(): void
    {
        config([
            'gemini.api_key' => 'test-key',
            'gemini.api_keys' => [],
            'gemini.image_model' => 'gemini-2.5-flash-image',
            'gemini.image_models' => ['gemini-2.5-flash-image'],
            'gemini.base_url' => 'https://example.test/v1beta',
        ]);

        Http::fake([
            'https://example.test/v1beta/models/gemini-2.5-flash-image:generateContent*' => Http::response([
                'error' => [
                    'message' => str_repeat('x', 400),
                ],
            ], 400),
        ]);

        try {
            app(GeminiClient::class)->generateImage([
                ['text' => 'Make it nicer'],
            ]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Gemini image API error (400):', $e->getMessage());
            $this->assertLessThanOrEqual(320, strlen($e->getMessage()));
            $this->assertStringEndsWith('…', $e->getMessage());
        }
    }

    #[Test]
    public function gemini_client_parses_inline_image_response(): void
    {
        config([
            'gemini.api_key' => 'test-key',
            'gemini.api_keys' => [],
            'gemini.image_model' => 'gemini-2.5-flash-image',
            'gemini.image_models' => ['gemini-2.5-flash-image'],
            'gemini.base_url' => 'https://example.test/v1beta',
        ]);

        Http::fake([
            'https://example.test/v1beta/models/gemini-2.5-flash-image:generateContent*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'inlineData' => [
                                'mimeType' => 'image/png',
                                'data' => $this->tinyPngBase64(),
                            ],
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $result = app(GeminiClient::class)->generateImage([
            ['text' => 'Make it nicer'],
        ]);

        $this->assertSame('image/png', $result['mime']);
        $this->assertSame($this->tinyPngBase64(), $result['base64']);
    }
}
