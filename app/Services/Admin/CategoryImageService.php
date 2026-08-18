<?php

namespace App\Services\Admin;

use App\Models\Category;
use App\Support\CleanJpegWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class CategoryImageService
{
    /** Longest edge for the stored category thumbnail (homepage cards). */
    public const MAX_EDGE = 600;

    public const EDGE_MD = 600;

    public const EDGE_SM = 400;

    public const EDGE_XS = 200;

    public const JPEG_QUALITY = 82;

    /**
     * @var array<string, int>
     */
    public const VARIANT_EDGES = [
        'md' => self::EDGE_MD,
        'sm' => self::EDGE_SM,
        'xs' => self::EDGE_XS,
    ];

    public function store(Category $category, UploadedFile $file): string
    {
        $directory = $this->categoryDirectory($category->id);
        File::ensureDirectoryExists($directory);

        $basename = now()->format('YmdHis').'_'.Str::lower(Str::random(6));
        $path = $this->persistVariants($file, $directory, $basename, $category->id);

        $oldPath = $category->thumb_image;
        $category->update(['thumb_image' => $path]);

        if ($oldPath && $oldPath !== $path) {
            $this->deleteLocalFile($oldPath);
        }

        return $path;
    }

    public function clear(Category $category): void
    {
        $oldPath = $category->thumb_image;

        $category->update(['thumb_image' => null]);

        if ($oldPath) {
            $this->deleteLocalFile($oldPath);
        }
    }

    public function deleteLocalFile(?string $path): void
    {
        if (! $path || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        // Only delete files this service wrote under /img/categories/{id}/…
        // Shared catalog originals (Earring.jpg, Necklace.jpg, …) must stay.
        if (! preg_match('#^img/categories/\d+/[^/]+$#', $normalized)) {
            return;
        }

        foreach ($this->variantPaths($normalized) as $relative) {
            $absolute = public_path($relative);

            if (is_file($absolute)) {
                @unlink($absolute);
            }
        }

        $directory = dirname(public_path($normalized));

        if (is_dir($directory) && File::isEmptyDirectory($directory)) {
            @rmdir($directory);
        }
    }

    /**
     * @return list<string>
     */
    private function variantPaths(string $normalized): array
    {
        $paths = [$normalized];

        if (preg_match('/_(xs|sm|md|lg)(\.[a-zA-Z0-9]+)$/i', $normalized)) {
            foreach (['xs', 'sm', 'md', 'lg'] as $variant) {
                $paths[] = preg_replace('/_(xs|sm|md|lg)(\.[a-zA-Z0-9]+)$/i', '_'.$variant.'$2', $normalized);
            }
        } else {
            foreach (['xs', 'sm', 'md'] as $variant) {
                $paths[] = preg_replace('/(\.[a-zA-Z0-9]+)$/i', '_'.$variant.'$1', $normalized);
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    private function categoryDirectory(int $categoryId): string
    {
        return public_path(implode(DIRECTORY_SEPARATOR, ['img', 'categories', (string) $categoryId]));
    }

    private function persistVariants(UploadedFile $file, string $directory, string $basename, int $categoryId): string
    {
        $source = $file->getRealPath();

        if (! $source || ! is_readable($source)) {
            throw new RuntimeException('Uploaded file is not readable.');
        }

        $info = @getimagesize($source);

        if ($info === false) {
            throw new RuntimeException('Could not read uploaded image.');
        }

        [$width, $height, $type] = $info;

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($source),
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException('Unsupported image type for category thumbnail.');
        }

        try {
            $masterName = $basename.'.jpg';
            $this->writeFit($image, $width, $height, $directory.DIRECTORY_SEPARATOR.$masterName, self::MAX_EDGE);

            foreach (self::VARIANT_EDGES as $variant => $edge) {
                if ($edge >= self::MAX_EDGE) {
                    continue;
                }

                $this->writeFit(
                    $image,
                    $width,
                    $height,
                    $directory.DIRECTORY_SEPARATOR.$basename.'_'.$variant.'.jpg',
                    $edge,
                );
            }
        } finally {
            imagedestroy($image);
        }

        return '/img/categories/'.$categoryId.'/'.$masterName;
    }

    /**
     * @param  \GdImage  $image
     */
    private function writeFit(mixed $image, int $width, int $height, string $destination, int $maxEdge): void
    {
        $scale = min(1.0, $maxEdge / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);

        if ($canvas === false) {
            throw new RuntimeException('Could not create thumbnail canvas.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        CleanJpegWriter::write($canvas, $destination, self::JPEG_QUALITY);
        imagedestroy($canvas);
    }
}
