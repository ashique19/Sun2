<?php

namespace App\Services\Admin;

use App\Models\Category;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class CategoryImageService
{
    /** Longest edge for category thumbnails (homepage cards). */
    public const MAX_EDGE = 600;

    public function store(Category $category, UploadedFile $file): string
    {
        $directory = $this->categoryDirectory($category->id);
        File::ensureDirectoryExists($directory);

        $filename = now()->format('YmdHis').'_'.Str::lower(Str::random(6)).'.jpg';
        $destination = $directory.DIRECTORY_SEPARATOR.$filename;

        $this->resizeAndSaveJpeg($file, $destination);

        $oldPath = $category->thumb_image;
        $path = '/img/categories/'.$category->id.'/'.$filename;

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

        if (! str_starts_with($normalized, 'img/categories/')) {
            return;
        }

        $absolute = public_path($normalized);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function categoryDirectory(int $categoryId): string
    {
        return public_path(implode(DIRECTORY_SEPARATOR, ['img', 'categories', (string) $categoryId]));
    }

    private function resizeAndSaveJpeg(UploadedFile $file, string $destination): void
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

        $max = self::MAX_EDGE;
        $scale = min(1.0, $max / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);

        if ($canvas === false) {
            imagedestroy($image);
            throw new RuntimeException('Could not create thumbnail canvas.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        $saved = imagejpeg($canvas, $destination, 82);
        imagedestroy($canvas);

        if (! $saved) {
            throw new RuntimeException('Could not save category thumbnail.');
        }
    }
}
