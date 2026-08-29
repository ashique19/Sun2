<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductPricedImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductPricedImageLogoTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function productWithPrimaryImage(): Product
    {
        $product = Product::query()->create([
            'name' => 'Logo Stamp Ring',
            'slug' => 'logo-stamp-ring',
            'price' => 400,
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
        $blue = imagecolorallocate($image, 40, 80, 140);
        imagefill($image, 0, 0, $blue);
        imagejpeg($image, $absolute, 90);
        imagedestroy($image);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => '/'.$relativeDir.'/'.$filename,
            'alt' => 'Logo Stamp Ring',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        return $product->fresh(['images']);
    }

    #[Test]
    public function generate_bakes_logo_position_into_layout_and_file(): void
    {
        $this->assertFileExists(public_path(ltrim(ProductPricedImageService::LOGO_PUBLIC_PATH, '/')));

        $product = $this->productWithPrimaryImage();
        $service = app(ProductPricedImageService::class);

        $path = $service->generate($product, [
            'position' => 'bottom-left',
            'font' => 48,
            'logo' => true,
            'logo_position' => 'top-right',
            'logo_size' => 22,
            'logo_x' => 0.88,
            'logo_y' => 0.12,
        ]);

        $product->refresh();

        $this->assertFileExists(public_path(ltrim($path, '/')));
        $this->assertTrue($product->priced_image_layout['logo']);
        $this->assertSame('top-right', $product->priced_image_layout['logo_position']);
        $this->assertSame(22, $product->priced_image_layout['logo_size']);
        $this->assertEqualsWithDelta(0.88, $product->priced_image_layout['logo_x'], 0.001);
        $this->assertEqualsWithDelta(0.12, $product->priced_image_layout['logo_y'], 0.001);
    }

    #[Test]
    public function edit_modal_exposes_logo_controls_and_persists_on_generate(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openPricedImageModal')
            ->assertSee('Put logo on image')
            ->assertSeeHtml('aria-label="Logo position"')
            ->assertSeeHtml('snapLogo(')
            ->set('pricedImagePosition', 'center')
            ->set('pricedImageFont', 56)
            ->set('pricedImageX', 0.5)
            ->set('pricedImageY', 0.5)
            ->set('pricedImageLogo', true)
            ->set('pricedImageLogoPosition', 'bottom-right')
            ->set('pricedImageLogoSize', 20)
            ->set('pricedImageLogoX', 0.88)
            ->set('pricedImageLogoY', 0.88)
            ->call('generatePricedImage')
            ->assertHasNoErrors();

        $product->refresh();

        $this->assertNotEmpty($product->priced_image_path);
        $this->assertTrue($product->priced_image_layout['logo']);
        $this->assertSame('bottom-right', $product->priced_image_layout['logo_position']);
        $this->assertSame(20, $product->priced_image_layout['logo_size']);
    }
}
