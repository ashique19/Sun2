<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Livewire\StorefrontProduct;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\GeminiClient;
use App\Services\Admin\ProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductAiSourceAndAdminOnlyTest extends TestCase
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
            'slug' => 'gold-necklace-ai-source',
            'price' => 1500,
            'is_published' => true,
            'stock_quantity' => 5,
        ]);
    }

    private function tinyPngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    #[Test]
    public function ai_modal_offers_existing_product_images_as_source(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();
        $image = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('source.jpg', 320, 240),
        );

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->call('openAiGenerateModal')
            ->assertSeeHtml('selectExistingSourceImage('.$image->id.')')
            ->assertSeeHtml('selectedSourceImageId === '.$image->id)
            ->assertSeeHtml('admin-only')
            ->assertSeeHtml('Or upload a raw photo');
    }

    #[Test]
    public function generate_from_existing_product_image_auto_saves_admin_only(): void
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
        $source = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('source.jpg', 320, 240),
        );

        $component = Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->set('aiPrompt', 'Clean white background jewelry photo')
            ->call('generateAiImage', '', 'image/jpeg', $source->id)
            ->assertSet('aiGenerateError', null)
            ->assertHasNoErrors()
            ->assertCount('aiCandidates', 1)
            ->assertSet('message', 'AI image saved (admin only — not shown on the storefront).');

        $candidate = $component->get('aiCandidates')[0];
        $this->assertNotEmpty($candidate['product_image_id'] ?? null);

        $saved = ProductImage::query()->findOrFail((int) $candidate['product_image_id']);
        $this->assertTrue($saved->is_admin_only);
        $this->assertFalse($saved->is_primary);
        $this->assertSame($product->id, $saved->product_id);
        $this->assertSame(2, ProductImage::query()->where('product_id', $product->id)->count());
    }

    #[Test]
    public function promote_makes_admin_only_image_public(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();
        $adminOnly = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('ai.jpg', 320, 240),
            $product->name.' (AI)',
            true,
        );

        $id = 'candidate-promote-1';
        $adminId = (int) auth()->id();
        $dir = storage_path('app/private/ai-candidates/'.$adminId);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$id.'.bin', base64_decode($this->tinyPngBase64(), true));
        File::put($dir.'/'.$id.'.json', json_encode(['mime' => 'image/png']));

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->set('aiCandidates', [[
                'id' => $id,
                'mime' => 'image/png',
                'name' => 'ai-1.png',
                'version' => 1,
                'product_image_id' => $adminOnly->id,
            ]])
            ->call('promoteAiCandidate', $id)
            ->assertCount('aiCandidates', 0)
            ->assertSet('message', 'AI image is now public on the product gallery.');

        $this->assertFalse($adminOnly->fresh()->is_admin_only);
        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
    }

    #[Test]
    public function discard_removes_linked_admin_only_product_image(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();
        $adminOnly = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('ai.jpg', 320, 240),
            $product->name.' (AI)',
            true,
        );

        $id = 'candidate-discard-1';
        $adminId = (int) auth()->id();
        $dir = storage_path('app/private/ai-candidates/'.$adminId);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$id.'.bin', 'bytes');
        File::put($dir.'/'.$id.'.json', json_encode(['mime' => 'image/png']));

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->set('aiCandidates', [[
                'id' => $id,
                'mime' => 'image/png',
                'name' => 'ai.png',
                'version' => 1,
                'product_image_id' => $adminOnly->id,
            ]])
            ->call('removeAiCandidate', $id)
            ->assertCount('aiCandidates', 0);

        $this->assertDatabaseMissing('product_images', ['id' => $adminOnly->id]);
    }

    #[Test]
    public function storefront_hides_admin_only_images(): void
    {
        $product = $this->product();
        $public = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('public.jpg', 320, 240),
        );
        $hidden = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('hidden.jpg', 320, 240),
            'Admin draft',
            true,
        );

        $component = Livewire::test(StorefrontProduct::class, ['product' => $product->fresh()]);

        $images = $component->get('product')->images;
        $this->assertCount(1, $images);
        $this->assertTrue($images->contains('id', $public->id));
        $this->assertFalse($images->contains('id', $hidden->id));
        $this->assertSame($public->path, $product->fresh()->primaryImagePath());
    }

    #[Test]
    public function set_primary_rejects_admin_only_images(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->product();
        app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('public.jpg', 320, 240),
        );
        $adminOnly = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('ai.jpg', 320, 240),
            'Admin draft',
            true,
        );

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->call('setPrimaryImage', $adminOnly->id)
            ->assertSet('message', 'Admin-only AI images cannot be the storefront primary.');

        $this->assertFalse($adminOnly->fresh()->is_primary);
    }
}
