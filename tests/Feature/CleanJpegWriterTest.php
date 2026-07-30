<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Admin\ProductImageService;
use App\Support\CleanJpegWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanJpegWriterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function write_creates_jpeg_without_exif_payload(): void
    {
        $canvas = imagecreatetruecolor(32, 24);
        $this->assertNotFalse($canvas);
        $color = imagecolorallocate($canvas, 200, 40, 40);
        imagefilledrectangle($canvas, 0, 0, 31, 23, $color);

        $path = storage_path('framework/testing/clean-jpeg-'.uniqid('', true).'.jpg');
        File::ensureDirectoryExists(dirname($path));

        try {
            CleanJpegWriter::write($canvas, $path, 85);
            imagedestroy($canvas);

            $this->assertFileExists($path);
            $info = getimagesize($path);
            $this->assertNotFalse($info);
            $this->assertSame(IMAGETYPE_JPEG, $info[2]);
            $this->assertTrue(CleanJpegWriter::appearsToLackExif($path));
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    #[Test]
    public function product_image_store_strips_exif_from_dirty_upload(): void
    {
        $product = Product::query()->create([
            'name' => 'Clean JPEG Product',
            'slug' => 'clean-jpeg-'.uniqid(),
            'price' => 500,
            'is_published' => true,
        ]);

        $dirtyPath = storage_path('framework/testing/dirty-exif-'.uniqid('', true).'.jpg');
        File::ensureDirectoryExists(dirname($dirtyPath));
        file_put_contents($dirtyPath, $this->jpegWithFakeExifApp1());
        $this->assertFalse(CleanJpegWriter::appearsToLackExif($dirtyPath));

        try {
            $upload = new UploadedFile($dirtyPath, 'dirty.jpg', 'image/jpeg', null, true);
            $image = app(ProductImageService::class)->store($product, $upload, 'Dirty');
            $absolute = public_path(ltrim($image->path, '/'));

            $this->assertFileExists($absolute);
            $this->assertTrue(CleanJpegWriter::appearsToLackExif($absolute));

            $base = preg_replace('/_lg\.jpg$/i', '', $absolute);
            $this->assertTrue(CleanJpegWriter::appearsToLackExif($base.'_md.jpg'));
            $this->assertTrue(CleanJpegWriter::appearsToLackExif($base.'_sm.jpg'));
            $this->assertTrue(CleanJpegWriter::appearsToLackExif($base.'_xs.jpg'));
        } finally {
            if (is_file($dirtyPath)) {
                @unlink($dirtyPath);
            }
        }
    }

    private function jpegWithFakeExifApp1(): string
    {
        $canvas = imagecreatetruecolor(40, 30);
        $fill = imagecolorallocate($canvas, 10, 20, 30);
        imagefilledrectangle($canvas, 0, 0, 39, 29, $fill);
        ob_start();
        imagejpeg($canvas, null, 90);
        imagedestroy($canvas);
        $jpeg = (string) ob_get_clean();

        $app1Payload = "Exif\x00\x00FAKE";
        $app1 = "\xFF\xE1".pack('n', strlen($app1Payload) + 2).$app1Payload;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }
}
