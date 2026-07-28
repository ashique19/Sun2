<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Livewire\Admin\AdminProducts;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductPricedImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductPricedImageTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function productWithPrimaryImage(string $name = 'Necklace Set'): Product
    {
        $product = Product::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'price' => 650,
            'compare_at_price' => 850,
            'is_published' => true,
        ]);

        $relativeDir = 'img/products/'.$product->id;
        $absoluteDir = public_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0775, true);
        }

        $filename = 'primary.jpg';
        $absolute = $absoluteDir.DIRECTORY_SEPARATOR.$filename;
        $image = imagecreatetruecolor(640, 640);
        $green = imagecolorallocate($image, 34, 120, 70);
        imagefill($image, 0, 0, $green);
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

    #[Test]
    public function normalize_layout_upgrades_legacy_font_indexes_and_guesses_corner(): void
    {
        $service = app(ProductPricedImageService::class);

        $this->assertSame([
            'position' => 'top-left',
            'font' => 64,
        ], $service->normalizeLayout(['x' => 24, 'y' => 24, 'font' => 5]));

        $this->assertSame([
            'position' => 'bottom-right',
            'font' => 56,
        ], $service->normalizeLayout(['x' => 200, 'y' => 200, 'font' => 4]));
    }

    #[Test]
    public function service_generates_priced_image_with_corner_and_large_font(): void
    {
        $product = $this->productWithPrimaryImage();
        $service = app(ProductPricedImageService::class);

        $path = $service->generate($product, [
            'position' => 'bottom-right',
            'font' => 72,
        ]);

        $product->refresh();

        $this->assertNotEmpty($path);
        $this->assertFileExists(public_path(ltrim($path, '/')));
        $this->assertSame($path, $product->priced_image_path);
        $this->assertSame('bottom-right', $product->priced_image_layout['position']);
        $this->assertSame(72, $product->priced_image_layout['font']);

        $info = getimagesize(public_path(ltrim($path, '/')));
        $this->assertNotFalse($info);
        $this->assertSame(640, $info[0]);
    }

    #[Test]
    public function products_list_button_creates_priced_image_and_shows_message(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage('List Product');

        Livewire::test(AdminProducts::class)
            ->call('generatePricedImage', $product->id)
            ->assertHasNoErrors()
            ->assertSet('message', 'Priced image created for “List Product”.');

        $product->refresh();
        $this->assertNotNull($product->priced_image_path);
        $this->assertFileExists(public_path(ltrim($product->priced_image_path, '/')));
    }

    #[Test]
    public function products_list_button_surfaces_missing_primary_image_error(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'No Photo',
            'slug' => 'no-photo',
            'price' => 500,
            'is_published' => true,
        ]);

        Livewire::test(AdminProducts::class)
            ->call('generatePricedImage', $product->id)
            ->assertHasErrors(['pricedImage'])
            ->assertSee('A primary product image is required first.');
    }

    #[Test]
    public function edit_modal_exposes_corner_presets_close_controls_and_larger_font_range(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openPricedImageModal')
            ->assertSet('showPricedImageModal', true)
            ->assertSeeHtml('x-teleport="body"')
            ->assertSeeHtml('h-dvh')
            ->assertSeeHtml('shrink-0 space-y-4 border-b')
            ->assertSee('Text position')
            ->assertSee('Top left')
            ->assertSee('Top right')
            ->assertSee('Bottom left')
            ->assertSee('Bottom right')
            ->assertSee('Text size (px)')
            ->assertSee('Close')
            ->assertDontSee('X position')
            ->assertDontSee('Y position')
            ->set('pricedImagePosition', 'bottom-left')
            ->set('pricedImageFont', 80)
            ->call('generatePricedImage')
            ->assertHasNoErrors()
            ->assertSet('message', 'Priced image generated.');

        $product->refresh();
        $this->assertSame('bottom-left', $product->priced_image_layout['position']);
        $this->assertSame(80, $product->priced_image_layout['font']);
        $this->assertNotNull($product->priced_image_path);
    }

    #[Test]
    public function edit_modal_can_be_closed(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openPricedImageModal')
            ->assertSet('showPricedImageModal', true)
            ->call('closePricedImageModal')
            ->assertSet('showPricedImageModal', false);
    }
}
