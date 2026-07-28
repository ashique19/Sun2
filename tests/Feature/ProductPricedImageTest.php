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

        Livewire::test(AdminProducts::class)
            ->assertSeeHtml('alt="Priced image for List Product"')
            ->assertSeeHtml($product->priced_image_path)
            ->assertSee('Rebuild');
    }

    #[Test]
    public function products_list_priced_image_column_has_no_thumb_when_missing(): void
    {
        $this->actingAs($this->adminUser());
        $this->productWithPrimaryImage('No Priced Yet');

        Livewire::test(AdminProducts::class)
            ->assertSee('Put price on image')
            ->assertDontSeeHtml('alt="Priced image for No Priced Yet"');
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
            ->assertSet('message', 'Priced image saved.');

        $product->refresh();
        $this->assertSame('bottom-left', $product->priced_image_layout['position']);
        $this->assertSame(80, $product->priced_image_layout['font']);
        $this->assertNotNull($product->priced_image_path);
    }

    #[Test]
    public function edit_page_shows_priced_image_preview_outside_the_modal(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage();
        $service = app(ProductPricedImageService::class);
        $path = $service->generate($product, [
            'position' => 'top-left',
            'font' => 48,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->assertSet('showPricedImageModal', false)
            ->assertSee('Shareable photo with price stamped on it')
            ->assertSeeHtml('alt="Priced image for Necklace Set"')
            ->assertSeeHtml($path);
    }

    #[Test]
    public function edit_page_shows_empty_priced_image_state_when_missing(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSee('No priced image yet. Use Put price on image to create one.');
    }

    #[Test]
    public function edit_modal_can_delete_priced_image_and_keeps_layout(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage();
        $service = app(ProductPricedImageService::class);
        $service->generate($product, [
            'position' => 'top-right',
            'font' => 48,
        ]);
        $product->refresh();
        $path = $product->priced_image_path;
        $this->assertNotNull($path);
        $this->assertFileExists(public_path(ltrim($path, '/')));

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->call('openPricedImageModal')
            ->assertSee('Save & rebuild')
            ->assertSeeHtml('wire:click="deletePricedImage"')
            ->assertSee('writes the position, text size, and priced image')
            ->call('deletePricedImage')
            ->assertSet('message', 'Priced image deleted.')
            ->assertDontSeeHtml('wire:click="deletePricedImage"')
            ->assertSee('Save & generate');

        $product->refresh();
        $this->assertNull($product->priced_image_path);
        $this->assertSame('top-right', $product->priced_image_layout['position']);
        $this->assertSame(48, $product->priced_image_layout['font']);
        $this->assertFileDoesNotExist(public_path(ltrim($path, '/')));
    }

    #[Test]
    public function regular_price_strikethrough_is_drawn_thicker_than_one_pixel(): void
    {
        $product = $this->productWithPrimaryImage();
        $service = app(ProductPricedImageService::class);
        $path = $service->generate($product, [
            'position' => 'top-left',
            'font' => 64,
        ]);

        $absolute = public_path(ltrim($path, '/'));
        $image = imagecreatefromjpeg($absolute);
        $this->assertNotFalse($image);

        $width = imagesx($image);
        $height = imagesy($image);
        $wideBlackRows = [];

        for ($y = 16; $y < min(180, $height); $y++) {
            $blackCount = 0;

            for ($x = 24; $x < min(260, $width); $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                if ($r < 40 && $g < 40 && $b < 40) {
                    $blackCount++;
                }
            }

            // A strike spans most of the price text width; glyph stems do not.
            if ($blackCount >= 50) {
                $wideBlackRows[] = $y;
            }
        }

        imagedestroy($image);

        $this->assertNotEmpty($wideBlackRows, 'Expected a horizontal strikethrough band.');

        $run = 1;
        $maxRun = 1;
        for ($i = 1; $i < count($wideBlackRows); $i++) {
            if ($wideBlackRows[$i] === $wideBlackRows[$i - 1] + 1) {
                $run++;
                $maxRun = max($maxRun, $run);
            } else {
                $run = 1;
            }
        }

        $this->assertGreaterThanOrEqual(3, $maxRun, 'Expected strikethrough thickness of at least 3px.');
    }

    #[Test]
    public function edit_priced_image_button_opens_modal_even_with_invalid_draft_fields(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSeeHtml('wire:key="priced-image-modal-host"')
            ->assertDontSeeHtml('aria-label="Priced image controls"')
            ->set('compare_at_price', '100') // invalid vs selling price 650 — must not block open
            ->set('price', '650')
            ->call('openPricedImageModal')
            ->assertHasNoErrors()
            ->assertSet('showPricedImageModal', true)
            ->assertSeeHtml('aria-label="Priced image controls"')
            ->assertSee('Text position')
            ->assertSee('Close');
    }
}
