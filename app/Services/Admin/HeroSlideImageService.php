<?php

namespace App\Services\Admin;

use App\Models\HeroSlide;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class HeroSlideImageService
{
    /** Longest edge for homepage hero images. */
    public const MAX_EDGE = 1920;

    public function store(HeroSlide $slide, UploadedFile $file): string
    {
        $directory = $this->slideDirectory($slide->id);
        File::ensureDirectoryExists($directory);

        $filename = now()->format('YmdHis').'_'.Str::lower(Str::random(6)).'.jpg';
        $destination = $directory.DIRECTORY_SEPARATOR.$filename;

        $this->resizeAndSaveJpeg($file, $destination);

        $oldPath = $slide->image;
        $path = '/img/hero/'.$slide->id.'/'.$filename;

        $slide->update(['image' => $path]);

        if ($oldPath && $oldPath !== $path) {
            $this->deleteLocalFile($oldPath);
        }

        return $path;
    }

    public function deleteLocalFile(?string $path): void
    {
        if (! $path || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        if (! str_starts_with($normalized, 'img/hero/')) {
            return;
        }

        $absolute = public_path($normalized);

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function slideDirectory(int $slideId): string
    {
        return public_path(implode(DIRECTORY_SEPARATOR, ['img', 'hero', (string) $slideId]));
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
            throw new RuntimeException('Unsupported image type for hero slide.');
        }

        $max = self::MAX_EDGE;
        $scale = min(1.0, $max / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);

        if ($canvas === false) {
            imagedestroy($image);
            throw new RuntimeException('Could not create hero image canvas.');
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        $saved = imagejpeg($canvas, $destination, 85);
        imagedestroy($canvas);

        if (! $saved) {
            throw new RuntimeException('Could not save hero image.');
        }
    }
}
