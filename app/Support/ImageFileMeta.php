<?php

namespace App\Support;

class ImageFileMeta
{
    /**
     * @return array{width: int|null, height: int|null, bytes: int|null, label: string}|null
     */
    public static function forPublicPath(?string $path): ?array
    {
        $absolute = self::absolutePath($path);

        if ($absolute === null) {
            return null;
        }

        $bytes = @filesize($absolute);
        $bytes = is_int($bytes) && $bytes >= 0 ? $bytes : null;

        $width = null;
        $height = null;
        $info = @getimagesize($absolute);

        if (is_array($info) && isset($info[0], $info[1])) {
            $width = max(0, (int) $info[0]);
            $height = max(0, (int) $info[1]);
        }

        if ($width === null && $height === null && $bytes === null) {
            return null;
        }

        return [
            'width' => $width,
            'height' => $height,
            'bytes' => $bytes,
            'label' => self::label($width, $height, $bytes),
        ];
    }

    public static function formatBytes(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) {
            return '';
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $kilobytes = $bytes / 1024;

        if ($kilobytes < 1024) {
            $rounded = $kilobytes >= 100
                ? (string) (int) round($kilobytes)
                : rtrim(rtrim(number_format($kilobytes, 1, '.', ''), '0'), '.');

            return $rounded.' KB';
        }

        $megabytes = $kilobytes / 1024;
        $rounded = $megabytes >= 10
            ? (string) (int) round($megabytes)
            : rtrim(rtrim(number_format($megabytes, 2, '.', ''), '0'), '.');

        return $rounded.' MB';
    }

    public static function label(?int $width, ?int $height, ?int $bytes): string
    {
        $parts = [];

        if ($width !== null && $height !== null && $width > 0 && $height > 0) {
            $parts[] = number_format($width).' × '.number_format($height);
        }

        $size = self::formatBytes($bytes);

        if ($size !== '') {
            $parts[] = $size;
        }

        return implode(' · ', $parts);
    }

    public static function absolutePath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $relative = StorefrontAssets::toRelativePath($path);

        if (! $relative) {
            return null;
        }

        $absolute = public_path($relative);

        return is_file($absolute) ? $absolute : null;
    }
}
