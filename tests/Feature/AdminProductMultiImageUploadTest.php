<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductMultiImageUploadTest extends TestCase
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
    public function staff_can_upload_multiple_gallery_images_at_once(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Multi Upload',
            'slug' => 'multi-upload',
            'price' => 1500,
            'is_published' => true,
        ]);

        $files = [
            UploadedFile::fake()->image('one.jpg', 120, 120),
            UploadedFile::fake()->image('two.png', 80, 80),
            UploadedFile::fake()->image('three.webp', 64, 64),
        ];

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('pendingAlts', ['Front', 'Side', 'Detail'])
            ->set('newImages', $files)
            ->call('uploadImages')
            ->assertHasNoErrors()
            ->assertSet('message', '3 images uploaded.');

        $this->assertSame(3, ProductImage::query()->where('product_id', $product->id)->count());
        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'alt' => 'Front',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'alt' => 'Side',
            'is_primary' => false,
        ]);
        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'alt' => 'Detail',
            'is_primary' => false,
        ]);

        foreach (ProductImage::query()->where('product_id', $product->id)->get() as $image) {
            $this->assertStringEndsWith('_lg.jpg', (string) $image->path);
            $absolute = public_path(ltrim((string) $image->path, '/'));
            $this->assertFileExists($absolute);
            $base = preg_replace('/_lg\.jpg$/i', '', $absolute);
            $this->assertFileExists($base.'_md.jpg');
            $this->assertFileExists($base.'_sm.jpg');
            $this->assertFileExists($base.'_xs.jpg');
        }
    }

    #[Test]
    public function add_images_queue_ui_exposes_upload_action_and_safe_editor_hooks(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Queue UI',
            'slug' => 'queue-ui',
            'price' => 900,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSeeHtml('Upload ${queue.length} image(s)')
            ->assertSeeHtml('@click="uploadAll()"')
            ->assertSeeHtml('@click.stop="openEditor(index)"')
            ->assertSeeHtml('@click.self="onEditorOutside()"')
            ->assertDontSeeHtml('@click.outside="closeEditor()"');
    }
}
