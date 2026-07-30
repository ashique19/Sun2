<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\User;
use App\Services\Admin\ProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductImageToneEditTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    #[Test]
    public function saved_editor_exposes_brightness_and_red_tone_controls(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Tone Edit',
            'slug' => 'tone-edit',
            'price' => 900,
            'is_published' => true,
        ]);

        app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('source.jpg', 640, 480),
        );

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->assertSeeHtml('Adjust tone')
            ->assertSeeHtml('editBrightness')
            ->assertSeeHtml('editRedTone')
            ->assertSeeHtml('saved-edit-brightness')
            ->assertSeeHtml('saved-edit-red-tone')
            ->assertSeeHtml('resetToneAdjustments()');

        $source = file_get_contents(resource_path('js/admin-product-images.js'));
        $this->assertIsString($source);
        $this->assertStringContainsString('editBrightness', $source);
        $this->assertStringContainsString('editRedTone', $source);
        $this->assertStringContainsString('applyRedTone', $source);
        $this->assertStringContainsString('brightness(${brightnessFactor})', $source);
    }
}
