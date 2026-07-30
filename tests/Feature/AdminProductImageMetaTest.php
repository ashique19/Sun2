<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\ImageFileMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductImageMetaTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @return array{0: Product, 1: ProductImage}
     */
    private function productWithImage(int $width = 800, int $height = 600): array
    {
        $product = Product::query()->create([
            'name' => 'Meta Product',
            'slug' => 'meta-product',
            'price' => 1200,
            'is_published' => true,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);

        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }

        $filename = 'meta_lg.jpg';
        $absolute = $absoluteDir.DIRECTORY_SEPARATOR.$filename;
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 40, 90, 140);
        imagefill($image, 0, 0, $color);
        imagejpeg($image, $absolute, 85);
        imagedestroy($image);

        $row = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/'.$filename,
            'alt' => 'Meta Product',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        return [$product->fresh(['images']), $row];
    }

    #[Test]
    public function image_file_meta_formats_dimensions_and_size(): void
    {
        $this->assertSame('512 B', ImageFileMeta::formatBytes(512));
        $this->assertSame('1.5 KB', ImageFileMeta::formatBytes(1536));
        $this->assertSame('1.5 MB', ImageFileMeta::formatBytes(1572864));
        $this->assertSame('800 × 600 · 1.5 KB', ImageFileMeta::label(800, 600, 1536));
    }

    #[Test]
    public function edit_page_shows_saved_image_dimensions_and_size(): void
    {
        $this->actingAs($this->adminUser());
        [$product, $image] = $this->productWithImage(640, 480);

        $meta = $image->fileMeta();
        $this->assertNotNull($meta);
        $this->assertSame(640, $meta['width']);
        $this->assertSame(480, $meta['height']);
        $this->assertNotNull($meta['bytes']);
        $this->assertGreaterThan(0, $meta['bytes']);
        $this->assertStringContainsString('640 × 480', $meta['label']);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSee('640 × 480')
            ->assertSeeHtml('title="Image dimensions and file size"');
    }

    #[Test]
    public function edit_page_shows_priced_image_meta_when_present(): void
    {
        $this->actingAs($this->adminUser());
        [$product] = $this->productWithImage();

        $relativeDir = 'img/products-priced/'.$product->id;
        $absoluteDir = public_path($relativeDir);

        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }

        $absolute = $absoluteDir.DIRECTORY_SEPARATOR.'priced.jpg';
        $image = imagecreatetruecolor(500, 400);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 160, 40));
        imagejpeg($image, $absolute, 85);
        imagedestroy($image);

        $product->update([
            'priced_image_path' => '/'.$relativeDir.'/priced.jpg',
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->assertSee('500 × 400')
            ->assertSeeHtml('title="Priced image dimensions and file size"');
    }

    #[Test]
    public function queue_ui_exposes_meta_label_for_pending_images(): void
    {
        $this->actingAs($this->adminUser());
        [$product] = $this->productWithImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSeeHtml('x-text="item.metaLabel"')
            ->assertSeeHtml('title="Image dimensions and file size"');

        $script = file_get_contents(resource_path('js/admin-product-images.js'));
        $this->assertNotFalse($script);
        $this->assertStringContainsString('refreshQueueItemMeta', $script);
        $this->assertStringContainsString('metaLabelFrom', $script);
        $this->assertStringContainsString('formatBytes', $script);
    }
}
