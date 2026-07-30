<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminProductEdit;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Admin\ProductImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductImageEditTest extends TestCase
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
    private function productWithImage(): array
    {
        $product = Product::query()->create([
            'name' => 'Edit Me',
            'slug' => 'edit-me',
            'price' => 1200,
            'is_published' => true,
        ]);

        $image = app(ProductImageService::class)->store(
            $product,
            UploadedFile::fake()->image('source.jpg', 900, 700),
            'Front',
        );

        return [$product->fresh(['images']), $image, public_path(ltrim($image->path, '/'))];
    }

    #[Test]
    public function saved_images_expose_edit_icon_and_saved_editor_modal_shell(): void
    {
        $this->actingAs($this->adminUser());
        [$product, $image] = $this->productWithImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->assertSeeHtml('openSavedEditor('.$image->id.',')
            ->assertSeeHtml('products\\/'.$product->id.'\\/images\\/'.$image->id.'\\/raw')
            ->assertSeeHtml('data-saved-crop-image')
            ->assertSeeHtml('data-saved-editor')
            ->assertSeeHtml('aria-label="Edit image"')
            ->assertSeeHtml('x-if="savedEditorOpen"')
            ->assertSeeHtml('rotateSaved(-90)')
            ->assertSeeHtml('setSavedAspect')
            ->assertSeeHtml('Live preview')
            ->assertSeeHtml('savedPreviewUrl')
            ->assertSeeHtml('Drag the crop box')
            ->assertSeeHtml('Put text on image')
            ->assertSeeHtml('Put logo on image')
            ->assertSeeHtml('overlayLogoPosition')
            ->assertSeeHtml('saveSavedEdit()')
            ->assertSeeHtml('wire:ignore')
            ->assertDontSeeHtml('x-show="savedEditorOpen"');
    }

    #[Test]
    public function replace_keeps_same_row_and_rebuilds_variants(): void
    {
        [$product, $image, $oldAbsolute] = $this->productWithImage();
        $oldId = $image->id;
        $oldPath = $image->path;
        $oldBase = preg_replace('/_lg\.jpg$/i', '', $oldAbsolute);

        $replacement = UploadedFile::fake()->image('edited.jpg', 1200, 800);
        $updated = app(ProductImageService::class)->replace($image, $replacement);

        $this->assertSame($oldId, $updated->id);
        $this->assertNotSame($oldPath, $updated->path);
        $this->assertStringEndsWith('_lg.jpg', $updated->path);
        $this->assertFileDoesNotExist($oldAbsolute);
        $this->assertFileDoesNotExist($oldBase.'_md.jpg');

        $lg = public_path(ltrim($updated->path, '/'));
        $this->assertFileExists($lg);
        $info = getimagesize($lg);
        $this->assertNotFalse($info);
        $this->assertSame(1200, $info[0]);
        $this->assertSame(800, $info[1]);

        $base = preg_replace('/_lg\.jpg$/i', '', $lg);
        $this->assertFileExists($base.'_md.jpg');
        $this->assertFileExists($base.'_sm.jpg');
        $this->assertFileExists($base.'_xs.jpg');
        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
    }

    #[Test]
    public function livewire_replace_edited_image_updates_gallery_file(): void
    {
        $this->actingAs($this->adminUser());
        [$product, $image, $oldAbsolute] = $this->productWithImage();
        $oldPath = $image->path;

        $jpeg = imagecreatetruecolor(640, 480);
        $color = imagecolorallocate($jpeg, 20, 80, 140);
        imagefill($jpeg, 0, 0, $color);
        ob_start();
        imagejpeg($jpeg, null, 85);
        imagedestroy($jpeg);
        $binary = ob_get_clean();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('replaceEditedImage', $image->id, base64_encode($binary), 'image/jpeg')
            ->assertHasNoErrors()
            ->assertSet('message', 'Image updated.');

        $image->refresh();
        $this->assertNotSame($oldPath, $image->path);
        $this->assertFileDoesNotExist($oldAbsolute);
        $this->assertFileExists(public_path(ltrim($image->path, '/')));

        $html = Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])->html();
        $this->assertStringContainsString('product-image-'.$image->id.'-'.md5($image->path), $html);
        $this->assertStringContainsString($image->path, $html);
        $this->assertStringNotContainsString('sundoritoma.com/public/'.$image->path, $html);
    }

    #[Test]
    public function replace_edited_image_requires_image_payload(): void
    {
        $this->actingAs($this->adminUser());
        [$product, $image] = $this->productWithImage();

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->call('replaceEditedImage', $image->id)
            ->assertHasErrors(['editedImage']);
    }

    #[Test]
    public function edit_modal_save_no_longer_uses_livewire_temp_upload_property(): void
    {
        $this->actingAs($this->adminUser());
        [$product] = $this->productWithImage();

        $html = Livewire::test(AdminProductEdit::class, ['product' => $product])->html();

        $this->assertStringContainsString('saveSavedEdit()', $html);
        $this->assertStringNotContainsString('wire:model="editedImage"', $html);
        $this->assertStringNotContainsString("\$wire.upload(\n                        'editedImage'", $html);

        $source = file_get_contents(resource_path('js/admin-product-images.js'));
        $this->assertIsString($source);
        $this->assertStringContainsString("replaceEditedImage(imageId, base64, 'image/jpeg')", $source);
        $this->assertStringContainsString('canvasToSaveJpeg', $source);
        $this->assertStringContainsString("updatedMessage !== 'Image updated.'", $source);
        $this->assertStringNotContainsString("\$wire.upload(\n                        'editedImage'", $source);
    }
}
