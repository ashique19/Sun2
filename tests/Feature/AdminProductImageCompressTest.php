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

class AdminProductImageCompressTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function largeJpegUpload(string $name = 'huge.jpg', int $width = 2400, int $height = 1800): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'prodimg_');
        $path = $tmp.'.jpg';
        rename($tmp, $path);

        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 40, 110, 180);
        imagefill($image, 0, 0, $color);
        imagejpeg($image, $path, 95);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    #[Test]
    public function store_downscales_to_lg_and_writes_compressed_variants(): void
    {
        $product = Product::query()->create([
            'name' => 'Compress Me',
            'slug' => 'compress-me',
            'price' => 1200,
            'is_published' => true,
        ]);

        $upload = $this->largeJpegUpload();
        $originalBytes = filesize($upload->getRealPath());

        $image = app(ProductImageService::class)->store($product, $upload, 'Front');

        $this->assertStringEndsWith('_lg.jpg', $image->path);
        $this->assertSame('Front', $image->alt);

        $lg = public_path(ltrim($image->path, '/'));
        $this->assertFileExists($lg);

        $info = getimagesize($lg);
        $this->assertNotFalse($info);
        $this->assertSame(ProductImageService::EDGE_LG, $info[0]);
        $this->assertSame(1200, $info[1]);

        $base = preg_replace('/_lg\.jpg$/i', '', $lg);
        foreach (['md' => ProductImageService::EDGE_MD, 'sm' => ProductImageService::EDGE_SM, 'xs' => ProductImageService::EDGE_XS] as $variant => $edge) {
            $path = $base.'_'.$variant.'.jpg';
            $this->assertFileExists($path);
            $variantInfo = getimagesize($path);
            $this->assertNotFalse($variantInfo);
            $this->assertSame($edge, $variantInfo[0]);
        }

        $this->assertLessThan($originalBytes, filesize($lg));
        $this->assertLessThan(450 * 1024, filesize($lg));
    }

    #[Test]
    public function store_does_not_upscale_small_images_but_still_writes_variants(): void
    {
        $product = Product::query()->create([
            'name' => 'Tiny',
            'slug' => 'tiny',
            'price' => 500,
            'is_published' => true,
        ]);

        $upload = $this->largeJpegUpload('small.jpg', 320, 240);
        $image = app(ProductImageService::class)->store($product, $upload);

        $lg = public_path(ltrim($image->path, '/'));
        $info = getimagesize($lg);
        $this->assertNotFalse($info);
        $this->assertSame(320, $info[0]);
        $this->assertSame(240, $info[1]);

        $base = preg_replace('/_lg\.jpg$/i', '', $lg);
        foreach (['md', 'sm', 'xs'] as $variant) {
            $this->assertFileExists($base.'_'.$variant.'.jpg');
        }
    }

    #[Test]
    public function delete_removes_all_variant_files(): void
    {
        $product = Product::query()->create([
            'name' => 'Delete Variants',
            'slug' => 'delete-variants',
            'price' => 700,
            'is_published' => true,
        ]);

        $image = app(ProductImageService::class)->store($product, $this->largeJpegUpload('del.jpg', 900, 900));
        $lg = public_path(ltrim($image->path, '/'));
        $base = preg_replace('/_lg\.jpg$/i', '', $lg);
        $paths = [$lg, $base.'_md.jpg', $base.'_sm.jpg', $base.'_xs.jpg'];

        foreach ($paths as $path) {
            $this->assertFileExists($path);
        }

        app(ProductImageService::class)->delete($image);

        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path);
        }
        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
    }

    #[Test]
    public function livewire_upload_stores_lg_path_and_variants(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Upload Compress',
            'slug' => 'upload-compress',
            'price' => 900,
            'is_published' => true,
        ]);

        Livewire::test(AdminProductEdit::class, ['product' => $product])
            ->set('newImages', [UploadedFile::fake()->image('livewire.jpg', 2000, 2000)])
            ->call('uploadImages')
            ->assertHasNoErrors()
            ->assertSet('message', 'Image uploaded.');

        $row = ProductImage::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($row);
        $this->assertStringEndsWith('_lg.jpg', $row->path);

        $lg = public_path(ltrim($row->path, '/'));
        $info = getimagesize($lg);
        $this->assertNotFalse($info);
        $this->assertSame(ProductImageService::EDGE_LG, $info[0]);
        $this->assertSame(ProductImageService::EDGE_LG, $info[1]);
    }

    #[Test]
    public function resize_defaults_use_lg_edge(): void
    {
        $this->actingAs($this->adminUser());

        $product = Product::query()->create([
            'name' => 'Defaults',
            'slug' => 'defaults',
            'price' => 400,
            'is_published' => true,
        ]);

        $image = app(ProductImageService::class)->store($product, $this->largeJpegUpload('d.jpg', 800, 600));

        Livewire::test(AdminProductEdit::class, ['product' => $product->fresh(['images'])])
            ->assertSet('resizeMaxWidths.'.$image->id, (string) ProductImageService::EDGE_LG)
            ->assertSet('resizeMaxHeights.'.$image->id, (string) ProductImageService::EDGE_LG);
    }
}
