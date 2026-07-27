<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\AiImagePrompt;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $raw = UploadedFile::fake()->image('raw.jpg', 40, 40);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openAiGenerateModal')
            ->assertSet('showAiGenerateModal', true)
            ->set('aiRawImage', $raw)
            ->set('aiPrompt', 'Clean white background jewelry photo')
            ->call('generateAiImage')
            ->assertSet('aiGenerateError', null)
            ->assertCount('aiCandidates', 1);

        $this->assertDatabaseHas('ai_image_prompts', [
            'prompt' => 'Clean white background jewelry photo',
        ]);

        $this->assertSame(1, AiImagePrompt::query()->count());
        $this->assertSame(1, (int) AiImagePrompt::query()->first()->use_count);
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
            ->set('aiRawImage', UploadedFile::fake()->image('raw.jpg'))
            ->set('aiPrompt', 'Studio lighting')
            ->call('generateAiImage')
            ->call('generateAiImage')
            ->assertCount('aiCandidates', 2);

        $this->assertSame(2, (int) AiImagePrompt::query()->where('prompt', 'Studio lighting')->value('use_count'));
    }

    #[Test]
    public function promote_adds_gallery_image_and_removes_candidate(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();
        $id = 'candidate-1';

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('aiCandidates', [[
                'id' => $id,
                'mime' => 'image/png',
                'base64' => $this->tinyPngBase64(),
                'name' => 'ai-1.png',
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

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('showAiGenerateModal', true)
            ->set('aiPrompt', 'keep me?')
            ->set('aiCandidates', [[
                'id' => 'x',
                'mime' => 'image/png',
                'base64' => $this->tinyPngBase64(),
                'name' => 'ai.png',
            ]])
            ->call('closeAiGenerateModal')
            ->assertSet('showAiGenerateModal', false)
            ->assertCount('aiCandidates', 0)
            ->assertSet('aiRawImage', null);
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
    public function gemini_client_parses_inline_image_response(): void
    {
        config([
            'gemini.api_key' => 'test-key',
            'gemini.image_model' => 'gemini-2.5-flash-image',
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
