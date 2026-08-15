<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProducts;
use App\Models\AiImagePrompt;
use App\Models\AiPromptGroup;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductsBulkAiGenerateTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function tinyPngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    }

    private function productWithPrimaryImage(string $name = 'Gold Ring'): Product
    {
        $product = Product::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->append('-')->append((string) str()->random(6))->toString(),
            'price' => 1200,
            'is_published' => true,
            'stock_quantity' => 3,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }

        $filename = 'primary.jpg';
        $absolute = $absoluteDir.DIRECTORY_SEPARATOR.$filename;
        $image = imagecreatetruecolor(320, 240);
        $gold = imagecolorallocate($image, 201, 162, 39);
        imagefill($image, 0, 0, $gold);
        imagejpeg($image, $absolute, 90);
        imagedestroy($image);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/'.$filename,
            'alt' => $name,
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        return $product->fresh(['images']);
    }

    private function promptGroup(string $name = 'Catalogue polish'): AiPromptGroup
    {
        $group = AiPromptGroup::query()->create([
            'name' => $name,
            'sort_order' => 0,
        ]);

        AiImagePrompt::query()->create([
            'ai_prompt_group_id' => $group->id,
            'prompt' => 'Extract the jewellery',
            'sort_order' => 0,
        ]);
        AiImagePrompt::query()->create([
            'ai_prompt_group_id' => $group->id,
            'prompt' => 'Change colour to rose gold',
            'sort_order' => 1,
        ]);

        return $group->fresh(['prompts']);
    }

    #[Test]
    public function open_modal_requires_selection_and_lists_prompt_groups(): void
    {
        $this->actingAs($this->adminUser());
        $this->promptGroup();
        $product = $this->productWithPrimaryImage();

        Livewire::test(AdminProducts::class)
            ->call('openBulkAiGenerateModal')
            ->assertSet('bulkAiModalOpen', false)
            ->assertSet('message', 'Select at least one product first.')
            ->call('toggleSelected', $product->id)
            ->call('openBulkAiGenerateModal')
            ->assertSet('bulkAiModalOpen', true)
            ->assertSee('Generate image with AI')
            ->assertSee('Catalogue polish')
            ->assertSee('Extract the jewellery');
    }

    #[Test]
    public function bulk_generate_runs_sequence_one_product_at_a_time_and_saves_admin_only_images(): void
    {
        config(['gemini.api_key' => 'test-key']);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('isConfigured')->andReturn(true);
        $gemini->shouldReceive('generateImage')
            ->times(4)
            ->andReturn([
                'mime' => 'image/png',
                'base64' => $this->tinyPngBase64(),
            ]);
        $this->app->instance(GeminiClient::class, $gemini);

        $this->actingAs($this->adminUser());
        $group = $this->promptGroup();
        $first = $this->productWithPrimaryImage('Ring One');
        $second = $this->productWithPrimaryImage('Ring Two');

        $component = Livewire::test(AdminProducts::class)
            ->call('toggleSelected', $first->id)
            ->call('toggleSelected', $second->id)
            ->call('openBulkAiGenerateModal')
            ->set('bulkAiPromptGroupId', $group->id)
            ->call('startBulkAiGenerate')
            ->assertSet('bulkAiRunning', false)
            ->assertSee('Finished. 2 succeeded, 0 failed.');

        $rows = $component->get('bulkAiRows');
        $this->assertCount(2, $rows);
        $this->assertSame('success', $rows[0]['status']);
        $this->assertSame('success', $rows[1]['status']);
        $this->assertSame(2, $rows[0]['steps_saved']);
        $this->assertSame(2, $rows[1]['steps_saved']);
        $this->assertSame([], $component->get('selected'));

        $this->assertSame(2, ProductImage::query()->where('product_id', $first->id)->where('is_admin_only', true)->count());
        $this->assertSame(2, ProductImage::query()->where('product_id', $second->id)->where('is_admin_only', true)->count());
    }

    #[Test]
    public function bulk_generate_marks_products_without_photos_as_failed_and_continues(): void
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
        $group = $this->promptGroup();
        $withPhoto = $this->productWithPrimaryImage('Has Photo');
        $withoutPhoto = Product::query()->create([
            'name' => 'No Photo',
            'slug' => 'no-photo-'.str()->random(6),
            'price' => 500,
            'is_published' => true,
        ]);

        $component = Livewire::test(AdminProducts::class)
            ->call('toggleSelected', $withoutPhoto->id)
            ->call('toggleSelected', $withPhoto->id)
            ->call('openBulkAiGenerateModal')
            ->set('bulkAiPromptGroupId', $group->id)
            ->call('startBulkAiGenerate')
            ->assertSee('Finished. 1 succeeded, 1 failed.');

        $rows = collect($component->get('bulkAiRows'))->keyBy('id');
        $this->assertSame('failed', $rows[$withoutPhoto->id]['status']);
        $this->assertStringContainsString('no photos', strtolower($rows[$withoutPhoto->id]['message']));
        $this->assertSame('success', $rows[$withPhoto->id]['status']);
        $this->assertSame([$withoutPhoto->id], $component->get('selected'));
    }

    #[Test]
    public function toolbar_shows_generate_image_with_ai_when_products_selected(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage();

        Livewire::test(AdminProducts::class)
            ->assertSee('Generate image with AI')
            ->call('toggleSelected', $product->id)
            ->assertSeeHtml('wire:click="openBulkAiGenerateModal"')
            ->assertSee('Generate image with AI (1)');
    }
}
