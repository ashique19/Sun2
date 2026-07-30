<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Write JPEGs that contain only pixel data (no EXIF / XMP / IPTC).
 *
 * GD never copies metadata into a new truecolor canvas + imagejpeg() output,
 * so every call site that goes through this helper exports a "clean" JPEG.
 */
class CleanJpegWriter
{
    /**
     * @param  \GdImage  $canvas
     */
    public static function write(
        mixed $canvas,
        string $absolutePath,
        int $quality = 82,
        bool $progressive = true,
    ): void {
        if (! $canvas instanceof \GdImage) {
            throw new RuntimeException('CleanJpegWriter expects a GD image canvas.');
        }

        $quality = max(0, min(100, $quality));
        $directory = dirname($absolutePath);

        if ($directory !== '' && $directory !== '.') {
            File::ensureDirectoryExists($directory);
        }

        if ($progressive && function_exists('imageinterlace')) {
            imageinterlace($canvas, true);
        }

        $saved = imagejpeg($canvas, $absolutePath, $quality);

        if (! $saved || ! is_file($absolutePath)) {
            throw new RuntimeException('Could not write clean JPEG.');
        }
    }

    /**
     * True when the JPEG bytes do not include an EXIF APP1 payload.
     */
    public static function appearsToLackExif(string $absolutePath): bool
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return false;
        }

        $bytes = (string) file_get_contents($absolutePath);

        if ($bytes === '' || ! str_starts_with($bytes, "\xFF\xD8")) {
            return false;
        }

        // EXIF in JPEG is stored in an APP1 segment starting with "Exif\0\0".
        return ! str_contains($bytes, "Exif\x00\x00");
    }
}
