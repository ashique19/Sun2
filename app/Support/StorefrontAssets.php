<?php

namespace App\Support;

class StorefrontAssets
{
    private const CDN_BASE = 'https://www.sundoritoma.com/public/';

    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            // Normalize legacy CDN URLs that omit /public/.
            if (preg_match('#^https?://(?:www\.)?sundoritoma\.com/(?!public/)(img/.+)$#i', $path, $matches)) {
                return self::CDN_BASE.$matches[1];
            }

            return $path;
        }

        $relative = self::toRelativePath($path);

        if (! $relative) {
            return null;
        }

        if (self::shouldProbeLocalFiles() && is_file(public_path($relative))) {
            return asset($relative);
        }

        return self::CDN_BASE.$relative;
    }

    public static function largestAvailableUrl(?string $pathOrUrl): ?string
    {
        if (! $pathOrUrl) {
            return null;
        }

        $path = self::toRelativePath($pathOrUrl);

        if (! $path) {
            return $pathOrUrl;
        }

        $candidates = [$path];

        if (preg_match('/_(xs|sm|md)(\.[a-zA-Z0-9]+)$/i', $path)) {
            $candidates = [
                preg_replace('/_(xs|sm|md)(\.[a-zA-Z0-9]+)$/i', '_lg$2', $path),
                preg_replace('/_(xs|sm|md)(\.[a-zA-Z0-9]+)$/i', '_md$2', $path),
                preg_replace('/_(xs|sm|md)(\.[a-zA-Z0-9]+)$/i', '_sm$2', $path),
                $path,
            ];
        }

        if (self::shouldProbeLocalFiles()) {
            foreach (array_unique($candidates) as $candidate) {
                if (is_file(public_path($candidate))) {
                    return self::url($candidate);
                }
            }
        }

        // CDN: prefer largest candidate without disk probes.
        return self::url($candidates[0] ?? $path);
    }

    public static function mediumUrl(?string $pathOrUrl): ?string
    {
        return self::variantUrl($pathOrUrl, 'md');
    }

    public static function smallUrl(?string $pathOrUrl): ?string
    {
        return self::variantUrl($pathOrUrl, 'sm');
    }

    public static function variantUrl(?string $pathOrUrl, string $variant = 'md'): ?string
    {
        if (! $pathOrUrl) {
            return null;
        }

        $variant = strtolower($variant);

        if (! in_array($variant, ['xs', 'sm', 'md', 'lg'], true)) {
            $variant = 'md';
        }

        $path = self::toRelativePath($pathOrUrl);

        if (! $path) {
            return $pathOrUrl;
        }

        if (preg_match('/_(xs|sm|md|lg)(\.[a-zA-Z0-9]+)$/i', $path)) {
            $path = preg_replace('/_(xs|sm|md|lg)(\.[a-zA-Z0-9]+)$/i', '_'.$variant.'$2', $path);
        }

        // Order line snapshots are usually _xs-only; product thumbs live under img/thumb.
        if (preg_match('#^img/order/(.+)$#i', $path, $matches)) {
            $path = 'img/thumb/'.$matches[1];
        }

        if (self::shouldProbeLocalFiles() && is_file(public_path($path))) {
            return asset($path);
        }

        return self::CDN_BASE.$path;
    }

    private static function shouldProbeLocalFiles(): bool
    {
        return app()->environment('local', 'testing');
    }

    private static function toRelativePath(string $pathOrUrl): ?string
    {
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            $path = parse_url($pathOrUrl, PHP_URL_PATH);

            if (! $path) {
                return null;
            }

            $path = ltrim($path, '/');
        } else {
            $path = ltrim(str_replace('\\', '/', $pathOrUrl), '/');
        }

        $path = preg_replace('#^public/#', '', $path) ?: $path;

        return $path !== '' ? $path : null;
    }
}
