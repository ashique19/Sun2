<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class ProductImageService
{
    public function store(Product $product, UploadedFile $file, ?string $alt = null): ProductImage
    {
        $directory = $this->productDirectory($product->id);
        File::ensureDirectoryExists($directory);

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = now()->format('YmdHis').'_'.Str::lower(Str::random(6)).'.'.$extension;
        $destination = $directory.DIRECTORY_SEPARATOR.$filename;

        $this->persistUploadedFile($file, $destination);

        $path = '/img/products/'.$product->id.'/'.$filename;
        $nextOrder = (int) $product->images()->max('sort_order') + 1;
        $isPrimary = ! $product->images()->exists();

        return ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $path,
            'alt' => $alt ?: $product->name,
            'sort_order' => $nextOrder,
            'is_primary' => $isPrimary,
            'perceptual_hash' => $this->safeHash($destination),
        ]);
    }

    private function safeHash(string $absolutePath): ?string
    {
        try {
            return app(ProductImageHashService::class)->hashFile($absolutePath);
        } catch (\Throwable) {
            return null;
        }
    }

    public function delete(ProductImage $image): void
    {
        $path = $image->path;

        DB::transaction(function () use ($image) {
            $wasPrimary = $image->is_primary;
            $productId = $image->product_id;

            $image->delete();

            if ($wasPrimary) {
                $replacement = ProductImage::query()
                    ->where('product_id', $productId)
                    ->orderBy('sort_order')
                    ->first();

                if ($replacement) {
                    $replacement->update(['is_primary' => true]);
                }
            }
        });

        $this->deleteLocalFile($path);
    }

    public function setPrimary(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            ProductImage::query()
                ->where('product_id', $image->product_id)
                ->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);
        });
    }

    /**
     * Downscale an existing gallery image to fit within max width/height (aspect preserved, never upscaled).
     */
    public function resize(ProductImage $image, int $maxWidth, int $maxHeight): ProductImage
    {
        if ($maxWidth < 1 || $maxHeight < 1) {
            throw new RuntimeException('Max width and height must be at least 1.');
        }

        $path = $image->path;

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            throw new RuntimeException('Remote images cannot be resized here.');
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        if (! str_starts_with($normalized, 'img/products/')) {
            throw new RuntimeException('Invalid product image path.');
        }

        $source = public_path($normalized);

        if (! is_file($source) || ! is_readable($source)) {
            throw new RuntimeException('Product image file is not readable.');
        }

        $info = @getimagesize($source);

        if ($info === false) {
            throw new RuntimeException('Could not read product image.');
        }

        [$width, $height, $type] = $info;

        $scale = min(1.0, $maxWidth / max(1, $width), $maxHeight / max(1, $height));

        if ($scale >= 1.0) {
            return $image;
        }

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $loaded = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($source),
            default => false,
        };

        if ($loaded === false) {
            throw new RuntimeException('Unsupported image type for resize.');
        }

        $canvas = imagecreatetruecolor($newWidth, $newHeight);

        if ($canvas === false) {
            imagedestroy($loaded);
            throw new RuntimeException('Could not create resize canvas.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $white);
        imagecopyresampled($canvas, $loaded, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($loaded);

        $directory = $this->productDirectory((int) $image->product_id);
        File::ensureDirectoryExists($directory);

        $filename = now()->format('YmdHis').'_'.Str::lower(Str::random(6)).'.jpg';
        $destination = $directory.DIRECTORY_SEPARATOR.$filename;
        $saved = imagejpeg($canvas, $destination, 85);
        imagedestroy($canvas);

        if (! $saved) {
            throw new RuntimeException('Could not save resized image.');
        }

        $newPath = '/img/products/'.$image->product_id.'/'.$filename;
        $oldPath = $image->path;

        $image->update([
            'path' => $newPath,
            'perceptual_hash' => $this->safeHash($destination),
        ]);

        if ($oldPath !== $newPath) {
            $this->deleteLocalFile($oldPath);
        }

        return $image->refresh();
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorder(Product $product, array $orderedIds): void
    {
        $ids = ProductImage::query()
            ->where('product_id', $product->id)
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        $orderedIds = array_values(array_intersect($orderedIds, $ids));

        if (count($orderedIds) !== count($ids)) {
            $orderedIds = $ids;
        }

        DB::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $index => $id) {
                ProductImage::query()->whereKey($id)->update(['sort_order' => $index]);
            }
        });
    }

    public function moveEarlier(ProductImage $image): void
    {
        $images = ProductImage::query()
            ->where('product_id', $image->product_id)
            ->orderBy('sort_order')
            ->get();

        $index = $images->search(fn (ProductImage $row) => $row->id === $image->id);

        if ($index === false || $index === 0) {
            return;
        }

        $ordered = $images->pluck('id')->all();
        [$ordered[$index - 1], $ordered[$index]] = [$ordered[$index], $ordered[$index - 1]];

        $this->reorder($image->product, $ordered);
    }

    public function moveLater(ProductImage $image): void
    {
        $images = ProductImage::query()
            ->where('product_id', $image->product_id)
            ->orderBy('sort_order')
            ->get();

        $index = $images->search(fn (ProductImage $row) => $row->id === $image->id);

        if ($index === false || $index >= $images->count() - 1) {
            return;
        }

        $ordered = $images->pluck('id')->all();
        [$ordered[$index + 1], $ordered[$index]] = [$ordered[$index], $ordered[$index + 1]];

        $this->reorder($image->product, $ordered);
    }

    public function deleteProduct(Product $product): void
    {
        $directory = $this->productDirectory($product->id);

        DB::transaction(function () use ($product) {
            $product->delete();
        });

        if (File::isDirectory($directory)) {
            File::deleteDirectory($directory);
        }
    }

    private function deleteLocalFile(string $path): void
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        if (! str_starts_with($normalized, 'img/products/')) {
            return;
        }

        $absolute = public_path($normalized);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function productDirectory(int $productId): string
    {
        return public_path(implode(DIRECTORY_SEPARATOR, ['img', 'products', (string) $productId]));
    }

    private function persistUploadedFile(UploadedFile $file, string $destination): void
    {
        $source = $file->getRealPath();

        if (! $source || ! is_readable($source)) {
            throw new RuntimeException('Uploaded file is not readable.');
        }

        if (@File::copy($source, $destination)) {
            return;
        }

        $contents = file_get_contents($source);

        if ($contents === false || ! File::put($destination, $contents)) {
            throw new RuntimeException('Could not save uploaded image.');
        }
    }
}
