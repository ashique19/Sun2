<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\GeminiClient;
use App\Services\Admin\ProductDescriptionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductAiDescriptionGenerateTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function productWithImage(): Product
    {
        $product = Product::query()->create([
            'name' => 'Pearl Jhumka',
            'slug' => 'pearl-jhumka',
            'price' => 1200,
            'is_published' => true,
            'description' => null,
            'description_bn' => null,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        File::ensureDirectoryExists($absoluteDir);

        $relative = $relativeDir.'/primary_lg.jpg';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        file_put_contents(public_path($relative), $png ?: '');

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relative,
            'alt' => 'Pearl Jhumka',
            'sort_order' => 0,
            'is_primary' => true,
        ]);

        return $product->fresh(['images']);
    }

    #[Test]
    public function product_edit_shows_bangla_description_and_generate_button(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $this->actingAs($this->adminUser());
        $product = $this->productWithImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSee('Generate EN + BN from image')
            ->assertSee('Bangla description')
            ->assertSeeHtml('data-rich-text-editor="description_bn"');
    }

    #[Test]
    public function generate_fills_english_and_bangla_from_gemini(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateJsonFromParts')
            ->once()
            ->andReturn([
                'description' => '<p>Elegant pearl jhumkas for festive wear.</p><script>bad()</script>',
                'description_bn' => '<p>উৎসবের জন্য মনোমুগ্ধকর পার্ল ঝুমকা।</p>',
            ]);
        $this->app->instance(GeminiClient::class, $gemini);

        $this->actingAs($this->adminUser());
        $product = $this->productWithImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('generateDescriptionsFromImage')
            ->assertSet('aiDescriptionError', null)
            ->assertSee('Descriptions generated')
            ->assertSet('description', '<p>Elegant pearl jhumkas for festive wear.</p>')
            ->assertSet('description_bn', '<p>উৎসবের জন্য মনোমুগ্ধকর পার্ল ঝুমকা।</p>');
    }

    #[Test]
    public function generate_requires_a_primary_image(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateJsonFromParts')->never();
        $this->app->instance(GeminiClient::class, $gemini);

        $this->actingAs($this->adminUser());
        $product = Product::query()->create([
            'name' => 'No Image Ring',
            'slug' => 'no-image-ring',
            'price' => 500,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('generateDescriptionsFromImage')
            ->assertSet('aiDescriptionError', 'Add a product image before generating descriptions.');
    }

    #[Test]
    public function save_persists_description_bn(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('description', '<p>English</p>')
            ->set('description_bn', '<p>বাংলা</p>')
            ->call('save')
            ->assertHasNoErrors();

        $product->refresh();
        $this->assertSame('<p>English</p>', $product->description);
        $this->assertSame('<p>বাংলা</p>', $product->description_bn);
    }

    #[Test]
    public function generator_service_sanitizes_html(): void
    {
        $service = app(ProductDescriptionGenerator::class);

        $clean = $service->sanitizeHtml('<p>Ok</p><script>x()</script><p onclick="y">Safe</p>');

        $this->assertStringContainsString('<p>Ok</p>', $clean);
        $this->assertStringContainsString('Safe', $clean);
        $this->assertStringNotContainsString('<script', strtolower($clean));
        $this->assertStringNotContainsString('onclick', strtolower($clean));
    }
}
