<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductImageResizeTest extends TestCase
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
     * @return array{0: Product, 1: ProductImage, 2: string}
     */
    private function productWithLargeImage(): array
    {
        $product = Product::query()->create([
            'name' => 'Resize Me',
            'slug' => 'resize-me',
            'price' => 1000,
            'is_published' => true,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }

        $filename = 'large.jpg';
        $absolute = $absoluteDir.DIRECTORY_SEPARATOR.$filename;
        $image = imagecreatetruecolor(1600, 1200);
        $blue = imagecolorallocate($image, 30, 90, 160);
        imagefill($image, 0, 0, $blue);
        imagejpeg($image, $absolute, 90);
        imagedestroy($image);

        $row = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/'.$filename,
            'alt' => 'Resize Me',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        return [$product->fresh(['images']), $row, $absolute];
    }

    #[Test]
    public function edit_page_shows_resize_controls_on_saved_images(): void
    {
        $this->actingAs($this->adminUser());
        [$product] = $this->productWithLargeImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSee('Resize (max size, keeps aspect)')
            ->assertSee('Max width')
            ->assertSee('Max height')
            ->assertSeeHtml('wire:click="resizeImage('.$product->images->first()->id.')"');
    }

    #[Test]
    public function admin_can_resize_saved_image_within_max_bounds(): void
    {
        $this->actingAs($this->adminUser());
        [$product, $image, $oldAbsolute] = $this->productWithLargeImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('resizeMaxWidths.'.$image->id, '800')
            ->set('resizeMaxHeights.'.$image->id, '600')
            ->call('resizeImage', $image->id)
            ->assertHasNoErrors()
            ->assertSet('message', 'Image resized.');

        $image->refresh();
        $this->assertNotSame('/img/products/'.$product->id.'/large.jpg', $image->path);
        $this->assertFileDoesNotExist($oldAbsolute);
        $this->assertFileExists(public_path(ltrim($image->path, '/')));

        $info = getimagesize(public_path(ltrim($image->path, '/')));
        $this->assertNotFalse($info);
        $this->assertSame(800, $info[0]);
        $this->assertSame(600, $info[1]);
    }

    #[Test]
    public function resize_does_not_upscale_smaller_images(): void
    {
        $this->actingAs($this->adminUser());
        [$product, $image] = $this->productWithLargeImage();

        // First shrink it.
        app(ProductImageService::class)->resize($image, 400, 300);
        $image->refresh();
        $pathBefore = $image->path;

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->set('resizeMaxWidths.'.$image->id, '2000')
            ->set('resizeMaxHeights.'.$image->id, '2000')
            ->call('resizeImage', $image->id)
            ->assertHasNoErrors()
            ->assertSet('message', 'Image already within those dimensions — nothing changed.');

        $this->assertSame($pathBefore, $image->fresh()->path);
    }

    #[Test]
    public function resize_requires_valid_max_dimensions(): void
    {
        $this->actingAs($this->adminUser());
        [$product, $image] = $this->productWithLargeImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('resizeMaxWidths.'.$image->id, '0')
            ->set('resizeMaxHeights.'.$image->id, '600')
            ->call('resizeImage', $image->id)
            ->assertHasErrors(['resizeMaxWidths.'.$image->id]);
    }
}
