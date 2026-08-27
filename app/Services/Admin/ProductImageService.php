<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\CleanJpegWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class ProductImageService
{
    /** Longest edge for the gallery master (`_lg`) — product page / zoom. */
    public const EDGE_LG = 1600;

    /** Listing / mid layouts. */
    public const EDGE_MD = 800;

    /** Thumbs and compact lists. */
    public const EDGE_SM = 400;

    /** Tiny thumbs and order-line snapshots. */
    public const EDGE_XS = 200;

    /** JPEG quality: small files without obvious quality loss for jewelry photos. */
    public const JPEG_QUALITY = 82;

    /** Hard cap for a single normalized master JPEG (AI candidates / uploads). */
    public const MAX_JPEG_BYTES = 2 * 1024 * 1024;

    /**
     * @var array<string, int>
     */
    public const VARIANT_EDGES = [
        'lg' => self::EDGE_LG,
        'md' => self::EDGE_MD,
        'sm' => self::EDGE_SM,
        'xs' => self::EDGE_XS,
    ];

    /**
     * Fit within max edge (never upscale) and encode a clean JPEG under maxBytes.
     * Matches the product gallery editor caps (1600×1600, ≤2MB).
     */
    public function normalizeToGalleryJpeg(
        string $binary,
        int $maxEdge = self::EDGE_LG,
        int $maxBytes = self::MAX_JPEG_BYTES,
    ): string {
        if ($binary === '') {
            throw new RuntimeException('Image data is empty.');
        }

        $maxEdge = max(1, min($maxEdge, self::EDGE_LG));
        $maxBytes = max(32 * 1024, $maxBytes);

        $loaded = @imagecreatefromstring($binary);

        if ($loaded === false) {
            throw new RuntimeException('Could not read image data.');
        }

        $width = imagesx($loaded);
        $height = imagesy($loaded);

        if ($width < 1 || $height < 1) {
            imagedestroy($loaded);
            throw new RuntimeException('Invalid image dimensions.');
        }

        $scale = min(1.0, $maxEdge / max($width, $height));
        $dstW = max(1, (int) round($width * $scale));
        $dstH = max(1, (int) round($height * $scale));
        $canvas = $this->resampleToCanvas($loaded, $width, $height, $dstW, $dstH);
        imagedestroy($loaded);

        $tempPath = tempnam(sys_get_temp_dir(), 'normjpg_');

        if ($tempPath === false) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not create a temporary file.');
        }

        $jpegPath = $tempPath.'.jpg';
        @unlink($tempPath);

        try {
            $quality = self::JPEG_QUALITY;
            $encoded = null;

            for ($attempt = 0; $attempt < 8; $attempt++) {
                CleanJpegWriter::write($canvas, $jpegPath, $quality);
                $encoded = (string) file_get_contents($jpegPath);

                if ($encoded !== '' && strlen($encoded) <= $maxBytes) {
                    break;
                }

                $quality = max(40, $quality - 10);

                if ($attempt >= 3) {
                    $dstW = max(640, (int) round($dstW * 0.8));
                    $dstH = max(640, (int) round($dstH * 0.8));
                    $shrunk = $this->resampleToCanvas($canvas, imagesx($canvas), imagesy($canvas), $dstW, $dstH);
                    imagedestroy($canvas);
                    $canvas = $shrunk;
                }
            }

            if ($encoded === null || $encoded === '' || strlen($encoded) > $maxBytes) {
                throw new RuntimeException('Image is still larger than '.$maxBytes.' bytes after compression.');
            }

            return $encoded;
        } finally {
            imagedestroy($canvas);

            if (is_file($jpegPath)) {
                @unlink($jpegPath);
            }
        }
    }

    public function store(Product $product, UploadedFile $file, ?string $alt = null, bool $adminOnly = false): ProductImage
    {
        $directory = $this->productDirectory($product->id);
        File::ensureDirectoryExists($directory);

        $basename = now()->format('YmdHis').'_'.Str::lower(Str::random(6));
        $path = $this->persistCompressedVariants($file->getRealPath() ?: '', $directory, $basename, $product->id);

        $nextOrder = (int) $product->images()->max('sort_order') + 1;
        $isPrimary = ! $adminOnly
            && ! $product->images()->where('is_admin_only', false)->exists();

        return ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $path,
            'alt' => $alt ?: $product->name,
            'sort_order' => $nextOrder,
            'is_primary' => $isPrimary,
            'is_admin_only' => $adminOnly,
            ...$this->safeHashAttributes(public_path(ltrim($path, '/'))),
        ]);
    }

    /**
     * @return array{perceptual_hash:?string, perceptual_hashes:?list<array{strategy:string,hash:string}>, dct_hash:?string}
     */
    private function safeHashAttributes(string $absolutePath): array
    {
        try {
            $bytes = is_file($absolutePath) ? file_get_contents($absolutePath) : false;
            if ($bytes === false || $bytes === '') {
                return [
                    'perceptual_hash' => null,
                    'perceptual_hashes' => null,
                    'dct_hash' => null,
                ];
            }

            $hasher = app(ProductImageHashService::class);
            $variants = $hasher->catalogHashVariantsFromBinary($bytes);
            $dctHash = null;
            try {
                $dctHash = $hasher->dctHashBinary($bytes);
            } catch (\Throwable) {
                // DCT is additive; dHash variants alone still work.
            }

            return [
                'perceptual_hash' => $variants[0]['hash'] ?? null,
                'perceptual_hashes' => $variants,
                'dct_hash' => $dctHash,
            ];
        } catch (\Throwable) {
            return [
                'perceptual_hash' => null,
                'perceptual_hashes' => null,
                'dct_hash' => null,
            ];
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
                    ->where('is_admin_only', false)
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
     * Replace an existing gallery image with a new upload (same DB row), rebuilding compressed variants.
     */
    public function replace(ProductImage $image, UploadedFile $file): ProductImage
    {
        $directory = $this->productDirectory((int) $image->product_id);
        File::ensureDirectoryExists($directory);

        $basename = now()->format('YmdHis').'_'.Str::lower(Str::random(6));
        $newPath = $this->persistCompressedVariants(
            $file->getRealPath() ?: '',
            $directory,
            $basename,
            (int) $image->product_id,
        );
        $oldPath = $image->path;

        $image->update([
            'path' => $newPath,
            ...$this->safeHashAttributes(public_path(ltrim($newPath, '/'))),
        ]);

        if ($oldPath !== $newPath) {
            $this->deleteLocalFile($oldPath);
        }

        return $image->refresh();
    }

    /**
     * Downscale an existing gallery image to fit within max width/height (aspect preserved, never upscaled),
     * then rewrite compressed `_lg` / `_md` / `_sm` / `_xs` variants.
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

        $source = $this->resolveAbsoluteSource($normalized);

        if (! is_file($source) || ! is_readable($source)) {
            throw new RuntimeException('Product image file is not readable.');
        }

        $info = @getimagesize($source);

        if ($info === false) {
            throw new RuntimeException('Could not read product image.');
        }

        [$width, $height] = $info;

        $capWidth = min($maxWidth, self::EDGE_LG);
        $capHeight = min($maxHeight, self::EDGE_LG);
        $scale = min(1.0, $capWidth / max(1, $width), $capHeight / max(1, $height));

        if ($scale >= 1.0 && $this->hasCompleteVariantSet($normalized)) {
            return $image;
        }

        $directory = $this->productDirectory((int) $image->product_id);
        File::ensureDirectoryExists($directory);

        $basename = now()->format('YmdHis').'_'.Str::lower(Str::random(6));
        $newPath = $this->persistCompressedVariants($source, $directory, $basename, (int) $image->product_id, $capWidth, $capHeight);
        $oldPath = $image->path;

        $image->update([
            'path' => $newPath,
            ...$this->safeHashAttributes(public_path(ltrim($newPath, '/'))),
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

        foreach ($this->siblingVariantAbsolutePaths($normalized) as $absolute) {
            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }

    private function productDirectory(int $productId): string
    {
        return public_path(implode(DIRECTORY_SEPARATOR, ['img', 'products', (string) $productId]));
    }

    /**
     * Load a source image, fit within bounds (never upscale), write `_lg`/`_md`/`_sm`/`_xs` JPEGs.
     *
     * @return string Public path to the `_lg` master (leading slash).
     */
    private function persistCompressedVariants(
        string $sourceAbsolute,
        string $directory,
        string $basename,
        int $productId,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
    ): string {
        if ($sourceAbsolute === '' || ! is_readable($sourceAbsolute)) {
            throw new RuntimeException('Uploaded file is not readable.');
        }

        $info = @getimagesize($sourceAbsolute);

        if ($info === false) {
            throw new RuntimeException('Could not read uploaded image.');
        }

        [$width, $height, $type] = $info;

        $loaded = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourceAbsolute),
            IMAGETYPE_PNG => @imagecreatefrompng($sourceAbsolute),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourceAbsolute) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($sourceAbsolute),
            default => false,
        };

        if ($loaded === false) {
            throw new RuntimeException('Unsupported image type for product gallery.');
        }

        $capWidth = $maxWidth ?? self::EDGE_LG;
        $capHeight = $maxHeight ?? self::EDGE_LG;
        $capWidth = max(1, min($capWidth, self::EDGE_LG));
        $capHeight = max(1, min($capHeight, self::EDGE_LG));

        $scale = min(1.0, $capWidth / max(1, $width), $capHeight / max(1, $height));
        $masterWidth = max(1, (int) round($width * $scale));
        $masterHeight = max(1, (int) round($height * $scale));

        $master = $this->resampleToCanvas($loaded, $width, $height, $masterWidth, $masterHeight);
        imagedestroy($loaded);

        $written = [];

        try {
            foreach (self::VARIANT_EDGES as $variant => $maxEdge) {
                $variantScale = min(1.0, $maxEdge / max($masterWidth, $masterHeight));
                $vw = max(1, (int) round($masterWidth * $variantScale));
                $vh = max(1, (int) round($masterHeight * $variantScale));

                if ($variant === 'lg' || ($vw === $masterWidth && $vh === $masterHeight)) {
                    $canvas = $master;
                    $ownsCanvas = false;
                } else {
                    $canvas = $this->resampleToCanvas($master, $masterWidth, $masterHeight, $vw, $vh);
                    $ownsCanvas = true;
                }

                $filename = $basename.'_'.$variant.'.jpg';
                $destination = $directory.DIRECTORY_SEPARATOR.$filename;

                CleanJpegWriter::write($canvas, $destination, self::JPEG_QUALITY);

                if ($ownsCanvas) {
                    imagedestroy($canvas);
                }

                if (! is_file($destination)) {
                    throw new RuntimeException('Could not save product image variant.');
                }

                $written[] = $destination;
            }
        } catch (\Throwable $e) {
            foreach ($written as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            imagedestroy($master);

            throw $e;
        }

        imagedestroy($master);

        return '/img/products/'.$productId.'/'.$basename.'_lg.jpg';
    }

    /**
     * @param  \GdImage  $source
     * @return \GdImage
     */
    private function resampleToCanvas(mixed $source, int $srcW, int $srcH, int $dstW, int $dstH): mixed
    {
        $canvas = imagecreatetruecolor($dstW, $dstH);

        if ($canvas === false) {
            throw new RuntimeException('Could not create image canvas.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $dstW, $dstH, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        return $canvas;
    }

    private function resolveAbsoluteSource(string $normalized): string
    {
        $candidates = $this->siblingVariantAbsolutePaths($normalized);

        // Prefer largest variant when rewriting.
        usort($candidates, function (string $a, string $b): int {
            $order = ['lg' => 0, 'md' => 1, 'sm' => 2, 'xs' => 3];
            $va = $this->variantKeyFromAbsolute($a);
            $vb = $this->variantKeyFromAbsolute($b);

            return ($order[$va] ?? 9) <=> ($order[$vb] ?? 9);
        });

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return public_path($normalized);
    }

    private function hasCompleteVariantSet(string $normalized): bool
    {
        foreach ($this->siblingVariantAbsolutePaths($normalized) as $absolute) {
            if (! is_file($absolute)) {
                return false;
            }
        }

        // Legacy single-file paths only return one sibling — not a complete set.
        return count($this->siblingVariantAbsolutePaths($normalized)) === 4;
    }

    /**
     * @return list<string>
     */
    private function siblingVariantAbsolutePaths(string $normalized): array
    {
        if (preg_match('/^(img\/products\/\d+\/.+?)_(xs|sm|md|lg)(\.[a-zA-Z0-9]+)$/i', $normalized, $matches)) {
            $paths = [];

            foreach (array_keys(self::VARIANT_EDGES) as $variant) {
                $paths[] = public_path($matches[1].'_'.$variant.'.jpg');
            }

            return $paths;
        }

        return [public_path($normalized)];
    }

    private function variantKeyFromAbsolute(string $absolute): string
    {
        if (preg_match('/_(xs|sm|md|lg)\.[a-zA-Z0-9]+$/i', $absolute, $matches)) {
            return strtolower($matches[1]);
        }

        return 'lg';
    }
}
