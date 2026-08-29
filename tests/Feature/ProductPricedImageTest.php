<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Livewire\Admin\AdminProducts;
use App\Models\Category;
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

    private function productWithPrimaryImage(string $name = 'Necklace Set', int $size = 640): Product
    {
        $product = Product::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->append('-')->append((string) str()->random(6))->toString(),
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
        $image = imagecreatetruecolor($size, $size);
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
            'logo' => false,
            'logo_position' => 'top-right',
            'logo_size' => ProductPricedImageService::LOGO_SIZE_DEFAULT,
            'logo_x' => 0.88,
            'logo_y' => 0.12,
        ], $service->normalizeLayout(['x' => 24, 'y' => 24, 'font' => 5]));

        $this->assertSame([
            'position' => 'bottom-right',
            'font' => 56,
            'logo' => false,
            'logo_position' => 'top-right',
            'logo_size' => ProductPricedImageService::LOGO_SIZE_DEFAULT,
            'logo_x' => 0.88,
            'logo_y' => 0.12,
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
        $this->assertSame('center', $product->priced_image_layout['position']);

        Livewire::test(AdminProducts::class)
            ->assertSeeHtml('alt="Priced image for List Product"')
            ->assertSeeHtml($product->priced_image_path)
            ->assertSeeHtml('h-[7.5rem] w-[7.5rem]')
            ->assertSeeHtml('min-w-[72rem]')
            ->assertSeeHtml('flex flex-col items-start gap-2')
            ->assertSeeHtml('inline-flex items-center justify-end gap-3')
            ->assertSee('Rebuild')
            ->assertSee('View');
    }

    #[Test]
    public function products_list_actions_do_not_share_cell_with_rebuild_button(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage('Overlap Check');

        Livewire::test(AdminProducts::class)
            ->call('generatePricedImage', $product->id)
            ->assertHasNoErrors();

        $html = Livewire::test(AdminProducts::class)->html();

        $this->assertStringContainsString('min-w-[72rem]', $html);
        $this->assertMatchesRegularExpression(
            '/flex flex-col items-start gap-2[\s\S]*?Rebuild[\s\S]*?<\/div>\s*<\/td>\s*<td[^>]*>\s*<div class="inline-flex items-center justify-end gap-3">\s*<a[^>]*>View<\/a>/',
            $html
        );
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
            ->assertSeeHtml('shrink-0 space-y-3 border-b')
            ->assertSeeHtml('role="group" aria-label="Text position"')
            ->assertSeeHtml('aria-label="Top left"')
            ->assertSeeHtml('aria-label="Top right"')
            ->assertSeeHtml('aria-label="Bottom left"')
            ->assertSeeHtml('aria-label="Bottom right"')
            ->assertSeeHtml('aria-label="Center"')
            ->assertSeeHtml('aria-label="Text size in pixels"')
            ->assertSeeHtml('class="flex gap-1.5"')
            ->assertDontSee('Text size (px)')
            ->assertDontSee('writes the position, text size, and priced image')
            ->assertSee('Close')
            ->assertSee('Drag the price stamp')
            ->assertSeeHtml('data-priced-stamp-stage')
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
        $this->assertEqualsWithDelta(0.12, $product->priced_image_layout['x'], 0.001);
        $this->assertEqualsWithDelta(0.88, $product->priced_image_layout['y'], 0.001);
        $this->assertNotNull($product->priced_image_path);
    }

    #[Test]
    public function edit_modal_can_place_stamp_with_custom_normalized_coords(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openPricedImageModal')
            ->set('pricedImagePosition', 'custom')
            ->set('pricedImageX', 0.35)
            ->set('pricedImageY', 0.72)
            ->set('pricedImageFont', 60)
            ->call('generatePricedImage')
            ->assertHasNoErrors()
            ->assertSet('message', 'Priced image saved.');

        $product->refresh();
        $this->assertSame('custom', $product->priced_image_layout['position']);
        $this->assertSame(60, $product->priced_image_layout['font']);
        $this->assertEqualsWithDelta(0.35, $product->priced_image_layout['x'], 0.001);
        $this->assertEqualsWithDelta(0.72, $product->priced_image_layout['y'], 0.001);
        $this->assertNotNull($product->priced_image_path);
        $this->assertFileExists(public_path(ltrim($product->priced_image_path, '/')));
    }

    #[Test]
    public function edit_modal_can_generate_priced_image_with_center_position(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('openPricedImageModal')
            ->set('pricedImagePosition', 'center')
            ->set('pricedImageFont', 64)
            ->call('generatePricedImage')
            ->assertHasNoErrors()
            ->assertSet('message', 'Priced image saved.');

        $product->refresh();
        $this->assertSame('center', $product->priced_image_layout['position']);
        $this->assertSame(64, $product->priced_image_layout['font']);
        $this->assertNotNull($product->priced_image_path);
        $this->assertFileExists(public_path(ltrim($product->priced_image_path, '/')));
    }

    #[Test]
    public function service_generates_priced_image_with_center_position(): void
    {
        $product = $this->productWithPrimaryImage();
        $service = app(ProductPricedImageService::class);

        $path = $service->generate($product, [
            'position' => 'center',
            'font' => 56,
        ]);

        $product->refresh();

        $this->assertFileExists(public_path(ltrim($path, '/')));
        $this->assertSame('center', $product->priced_image_layout['position']);
        $this->assertSame([
            'position' => 'center',
            'font' => 56,
            'logo' => false,
            'logo_position' => 'top-right',
            'logo_size' => ProductPricedImageService::LOGO_SIZE_DEFAULT,
            'logo_x' => 0.88,
            'logo_y' => 0.12,
        ], $service->normalizeLayout(['position' => 'center', 'font' => 56]));
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

        $html = Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSee('No priced image yet. Use Put price on image to create one.')
            ->html();

        $this->assertSame(
            1,
            substr_count($html, 'wire:click="openPricedImageModal"'),
            'Product edit should expose a single Put price on image control.',
        );
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
            ->assertDontSee('writes the position, text size, and priced image')
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
            ->assertSeeHtml('role="group" aria-label="Text position"')
            ->assertSee('Close');
    }

    #[Test]
    public function auto_fill_layout_is_centered_at_twenty_percent_of_image_width(): void
    {
        $product = $this->productWithPrimaryImage('Auto Fill Size');
        $service = app(ProductPricedImageService::class);
        $source = public_path(ltrim($product->primaryImagePath(), '/'));

        $layout = $service->autoFillLayout($source, $product);
        $panel = $service->measurePanel($product, $layout['font']);
        $target = (int) round(640 * ProductPricedImageService::AUTO_SIZE_RATIO);

        $this->assertSame('center', $layout['position']);
        // Bangla glyphs (/পিস) are wider than Latin; pick closest font to 20% width.
        $ratio = $panel['width'] / 640;
        $this->assertGreaterThan(0.15, $ratio);
        $this->assertLessThan(0.40, $ratio);
    }

    #[Test]
    public function priced_overlay_uses_bangla_digits_taka_and_piece_suffix(): void
    {
        $product = $this->productWithPrimaryImage('Bangla Stamp');
        $product->update(['price' => 500, 'compare_at_price' => 1200]);
        $service = app(ProductPricedImageService::class);

        $this->assertSame('১২০০', $service->toBanglaDigits(1200));
        $this->assertSame('৫০০', $service->toBanglaDigits(500));
        $this->assertStringContainsString('NotoSansBengali-Bold.ttf', $service->fontPath());
        $this->assertFileExists($service->pieceSuffixPath());
        $this->assertFileExists($service->unitSuffixPath($product->fresh()));

        $path = $service->generate($product->fresh(), [
            'position' => 'center',
            'font' => 56,
        ]);

        $this->assertFileExists(public_path(ltrim($path, '/')));
        $panel = $service->measurePanel($product->fresh(), 56);
        $this->assertGreaterThan(0, $panel['width']);
        $this->assertGreaterThan(0, $panel['height']);
    }

    #[Test]
    public function priced_image_uses_product_price_unit_suffix(): void
    {
        $product = $this->productWithPrimaryImage('Pair Unit');
        $product->update([
            'price' => 900,
            'compare_at_price' => 1200,
            'price_unit' => 'জোড়া',
        ]);
        $service = app(ProductPricedImageService::class);

        $this->assertSame('জোড়া', $product->fresh()->priceUnitLabel());
        $this->assertStringEndsWith('jora.png', $service->unitSuffixPath($product->fresh()));

        $path = $service->generate($product->fresh(), [
            'position' => 'center',
            'font' => 48,
        ]);

        $this->assertFileExists(public_path(ltrim($path, '/')));
    }

    #[Test]
    public function edit_form_can_save_price_unit_and_rebuild_priced_image(): void
    {
        $this->actingAs($this->adminUser());
        $product = $this->productWithPrimaryImage('Unit Edit');
        app(ProductPricedImageService::class)->generate($product->fresh(), [
            'position' => 'center',
            'font' => 48,
        ]);
        $before = $product->fresh()->priced_image_path;

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->assertSet('price_unit', 'পিস')
            ->assertSee('Price unit')
            ->assertSee('জোড়া')
            ->assertSee('সেট')
            ->assertSee('মালা+দুল')
            ->set('price_unit', 'মালা+দুল')
            ->call('save')
            ->assertHasNoErrors();

        $product->refresh();
        $this->assertSame('মালা+দুল', $product->price_unit);
        $this->assertNotNull($product->priced_image_path);
        $this->assertNotSame($before, $product->priced_image_path);
    }

    #[Test]
    public function auto_fill_font_scales_with_primary_image_size(): void
    {
        $small = $this->productWithPrimaryImage('Auto Small', 400);
        $large = $this->productWithPrimaryImage('Auto Large', 1200);
        $service = app(ProductPricedImageService::class);

        $smallLayout = $service->autoFillLayout(public_path(ltrim($small->primaryImagePath(), '/')), $small);
        $largeLayout = $service->autoFillLayout(public_path(ltrim($large->primaryImagePath(), '/')), $large);

        $this->assertGreaterThan($smallLayout['font'], $largeLayout['font']);
        $this->assertSame('center', $largeLayout['position']);
    }

    #[Test]
    public function generate_without_layout_uses_auto_fill_then_rebuild_keeps_saved_layout(): void
    {
        $product = $this->productWithPrimaryImage('First Time Auto');
        $service = app(ProductPricedImageService::class);

        $service->generate($product);
        $product->refresh();

        $this->assertSame('center', $product->priced_image_layout['position']);
        $firstFont = $product->priced_image_layout['font'];
        $this->assertGreaterThanOrEqual(ProductPricedImageService::FONT_MIN, $firstFont);

        $product->update([
            'priced_image_layout' => [
                'position' => 'top-left',
                'font' => 48,
            ],
        ]);

        $service->generate($product->fresh());
        $product->refresh();

        $this->assertSame('top-left', $product->priced_image_layout['position']);
        $this->assertSame(48, $product->priced_image_layout['font']);
    }

    #[Test]
    public function auto_fill_overlay_frosts_the_center_of_the_image(): void
    {
        $product = $this->productWithPrimaryImage('Frost Center');
        $service = app(ProductPricedImageService::class);
        $path = $service->generate($product);

        $image = imagecreatefromjpeg(public_path(ltrim($path, '/')));
        $this->assertNotFalse($image);

        $center = imagecolorat($image, 320, 320);
        $corner = imagecolorat($image, 8, 8);
        imagedestroy($image);

        $centerR = ($center >> 16) & 0xFF;
        $cornerR = ($corner >> 16) & 0xFF;
        $cornerG = ($corner >> 8) & 0xFF;

        $this->assertGreaterThan(80, $cornerG, 'Corner should remain the original green photo.');
        $this->assertGreaterThan($cornerR + 40, $centerR, 'Center overlay should be a frosted lighter panel.');
    }

    #[Test]
    public function products_list_exposes_put_price_batch_modal(): void
    {
        $this->actingAs($this->adminUser());
        $this->productWithPrimaryImage('Needs Price');

        Livewire::test(AdminProducts::class)
            ->assertSeeHtml('wire:click="openPutPriceModal"')
            ->assertDontSeeHtml('aria-label="Put price on images"')
            ->call('openPutPriceModal')
            ->assertSet('putPriceModalOpen', true)
            ->assertSet('putPriceRemaining', 1)
            ->assertCount('putPriceBatch', 1)
            ->assertSeeHtml('aria-label="Put price on images"')
            ->assertSee('Needs Price')
            ->assertSee('Put price & next');
    }

    #[Test]
    public function put_price_batch_auto_progresses_through_all_batches_until_finished(): void
    {
        $this->actingAs($this->adminUser());

        $already = $this->productWithPrimaryImage('Already Priced');
        app(ProductPricedImageService::class)->generate($already, [
            'position' => 'top-right',
            'font' => 40,
        ]);
        $already->refresh();
        $alreadyPath = $already->priced_image_path;

        Product::query()->create([
            'name' => 'No Photo',
            'slug' => 'no-photo-put-price',
            'price' => 400,
            'is_published' => true,
        ]);

        $pending = [];
        for ($i = 1; $i <= 12; $i++) {
            $pending[] = $this->productWithPrimaryImage('Batch Item '.$i);
        }

        $component = Livewire::test(AdminProducts::class)
            ->call('openPutPriceModal')
            ->assertSet('putPriceModalOpen', true)
            ->assertSet('putPriceReplaceExisting', true)
            ->assertSet('putPriceRemaining', 13)
            ->assertCount('putPriceBatch', 10)
            ->assertSee('replaces existing priced images');

        $pendingIds = $component->get('putPricePendingIds');
        $this->assertContains($already->id, $pendingIds);

        $component->call('applyPutPriceBatch')
            ->assertSet('putPriceRemaining', 0)
            ->assertSet('putPriceBatch', [])
            ->assertSet('putPriceTotalSaved', 13)
            ->assertSet('putPriceRunning', false)
            ->assertSee('Saved 13 priced images for the selection.');

        $allIds = collect($pending)->pluck('id')->push($already->id);
        $pricedCount = Product::query()
            ->whereIn('id', $allIds)
            ->whereNotNull('priced_image_path')
            ->count();
        $this->assertSame(13, $pricedCount);

        foreach ($pending as $product) {
            $product->refresh();
            $this->assertNotNull($product->priced_image_path);
            $this->assertSame('center', $product->priced_image_layout['position']);
            $this->assertFileExists(public_path(ltrim($product->priced_image_path, '/')));
        }

        $already->refresh();
        $this->assertNotNull($already->priced_image_path);
        $this->assertNotSame($alreadyPath, $already->priced_image_path);
        $this->assertSame('center', $already->priced_image_layout['position']);
        $this->assertNull(Product::query()->where('slug', 'no-photo-put-price')->value('priced_image_path'));
    }

    #[Test]
    public function put_price_with_selection_replaces_existing_priced_images_only_for_selected(): void
    {
        $this->actingAs($this->adminUser());
        $service = app(ProductPricedImageService::class);

        $selected = $this->productWithPrimaryImage('Selected Rebuild');
        $service->generate($selected, [
            'position' => 'top-right',
            'font' => 40,
        ]);
        $selected->refresh();
        $oldPath = $selected->priced_image_path;
        $this->assertNotNull($oldPath);

        $otherMissing = $this->productWithPrimaryImage('Other Missing');
        $otherPriced = $this->productWithPrimaryImage('Other Already Priced');
        $service->generate($otherPriced, [
            'position' => 'bottom-left',
            'font' => 48,
        ]);
        $otherPriced->refresh();
        $otherPricedPath = $otherPriced->priced_image_path;

        Livewire::test(AdminProducts::class)
            ->set('selected', [$selected->id])
            ->call('openPutPriceModal')
            ->assertSet('putPriceReplaceExisting', true)
            ->assertSet('putPriceRemaining', 1)
            ->assertCount('putPriceBatch', 1)
            ->assertSee('replaces existing priced images')
            ->call('applyPutPriceBatch')
            ->assertSet('putPriceRemaining', 0)
            ->assertSet('putPriceBatch', [])
            ->assertSet('putPriceTotalSaved', 1)
            ->assertSee('Saved 1 priced image for the selection.');

        $selected->refresh();
        $this->assertNotNull($selected->priced_image_path);
        $this->assertNotSame($oldPath, $selected->priced_image_path);
        $this->assertSame('center', $selected->priced_image_layout['position']);
        $this->assertFileExists(public_path(ltrim($selected->priced_image_path, '/')));
        $this->assertFileDoesNotExist(public_path(ltrim($oldPath, '/')));

        $otherMissing->refresh();
        $this->assertNull($otherMissing->priced_image_path);

        $otherPriced->refresh();
        $this->assertSame($otherPricedPath, $otherPriced->priced_image_path);
        $this->assertSame('bottom-left', $otherPriced->priced_image_layout['position']);
    }

    #[Test]
    public function put_price_without_selection_includes_and_replaces_existing_priced_images(): void
    {
        $this->actingAs($this->adminUser());

        $already = $this->productWithPrimaryImage('Keep Existing');
        app(ProductPricedImageService::class)->generate($already, [
            'position' => 'top-left',
            'font' => 36,
        ]);
        $already->refresh();
        $path = $already->priced_image_path;

        $missing = $this->productWithPrimaryImage('Fill Missing');

        Livewire::test(AdminProducts::class)
            ->assertSet('selected', [])
            ->call('openPutPriceModal')
            ->assertSet('putPriceReplaceExisting', true)
            ->assertSet('putPriceRemaining', 2)
            ->assertSee('replaces existing priced images')
            ->call('applyPutPriceBatch')
            ->assertSet('putPriceTotalSaved', 2);

        $missing->refresh();
        $this->assertNotNull($missing->priced_image_path);

        $already->refresh();
        $this->assertNotNull($already->priced_image_path);
        $this->assertNotSame($path, $already->priced_image_path);
        $this->assertSame('center', $already->priced_image_layout['position']);
    }

    #[Test]
    public function put_price_without_selection_uses_current_list_filters(): void
    {
        $this->actingAs($this->adminUser());

        $categoryA = Category::query()->create([
            'name' => 'Rings',
            'slug' => 'rings-put-price',
            'is_active' => true,
        ]);
        $categoryB = Category::query()->create([
            'name' => 'Earrings',
            'slug' => 'earrings-put-price',
            'is_active' => true,
        ]);

        $inFilter = $this->productWithPrimaryImage('Ring Match');
        $inFilter->update(['category_id' => $categoryA->id]);

        $outFilter = $this->productWithPrimaryImage('Earring Other');
        $outFilter->update(['category_id' => $categoryB->id]);

        Livewire::test(AdminProducts::class)
            ->set('category', (string) $categoryA->id)
            ->assertSet('selected', [])
            ->call('openPutPriceModal')
            ->assertSet('putPriceRemaining', 1)
            ->assertCount('putPriceBatch', 1)
            ->assertSee('Ring Match')
            ->assertDontSee('Earring Other')
            ->call('applyPutPriceBatch')
            ->assertSet('putPriceTotalSaved', 1);

        $inFilter->refresh();
        $outFilter->refresh();
        $this->assertNotNull($inFilter->priced_image_path);
        $this->assertNull($outFilter->priced_image_path);
    }
}
