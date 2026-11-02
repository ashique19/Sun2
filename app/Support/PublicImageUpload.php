<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class PublicImageUpload
{
    /**
     * Turn a public-disk relative path into an UploadedFile for Graph image sends.
     */
    public static function fromRelativePath(string $relativePath): UploadedFile
    {
        $absolute = public_path(ltrim($relativePath, '/'));

        if (! is_file($absolute) || ! is_readable($absolute)) {
            throw new RuntimeException('Image file is missing on disk.');
        }

        $mime = mime_content_type($absolute) ?: 'image/jpeg';
        if (! str_starts_with($mime, 'image/')) {
            throw new RuntimeException('File is not a valid image.');
        }

        return new UploadedFile(
            $absolute,
            basename($absolute),
            $mime,
            null,
            true,
        );
    }
}
