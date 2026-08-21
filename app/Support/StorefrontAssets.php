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

        // Prefer a file that exists locally (e.g. freshly replaced gallery edits)
        // so the UI does not keep serving a missing/stale CDN object.
        if (is_file(public_path($relative))) {
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

        foreach (array_unique($candidates) as $candidate) {
            if (is_file(public_path($candidate))) {
                return self::url($candidate);
            }
        }

        // CDN / remote: prefer largest candidate path.
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

    public static function extraSmallUrl(?string $pathOrUrl): ?string
    {
        return self::variantUrl($pathOrUrl, 'xs');
    }

    /**
     * Build a responsive srcset for listing/thumb images.
     * Only includes variants that resolve to distinct files — never advertise the
     * same full-size legacy image as 200w/400w/800w ( inflates payloads ).
     *
     * @param  array<string, int>  $widths  variant => CSS pixel width hint
     */
    public static function srcset(?string $pathOrUrl, array $widths = [
        'xs' => 200,
        'sm' => 400,
        'md' => 800,
    ]): ?string
    {
        if (! $pathOrUrl) {
            return null;
        }

        $parts = [];
        $seenUrls = [];

        foreach ($widths as $variant => $width) {
            $url = self::exactVariantUrl($pathOrUrl, (string) $variant);

            if (! $url || isset($seenUrls[$url])) {
                continue;
            }

            $seenUrls[$url] = true;
            $parts[] = $url.' '.(int) $width.'w';
        }

        // A single URL is not a useful srcset; callers already set src=.
        return count($parts) >= 2 ? implode(', ', $parts) : null;
    }

    /**
     * Resolve a sized sibling only — no fallback to the unsuffixed original.
     */
    public static function exactVariantUrl(?string $pathOrUrl, string $variant = 'md'): ?string
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
            return null;
        }

        if (preg_match('/_(xs|sm|md|lg)(\.[a-zA-Z0-9]+)$/i', $path)) {
            $candidate = preg_replace('/_(xs|sm|md|lg)(\.[a-zA-Z0-9]+)$/i', '_'.$variant.'$2', $path);
        } else {
            $candidate = preg_replace('/(\.[a-zA-Z0-9]+)$/i', '_'.$variant.'$1', $path);
        }

        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        if (preg_match('#^img/order/(.+)$#i', $candidate, $matches)) {
            $thumbCandidate = 'img/thumb/'.$matches[1];
            if (is_file(public_path($thumbCandidate))) {
                return asset($thumbCandidate);
            }
        }

        if (is_file(public_path($candidate))) {
            return asset($candidate);
        }

        // CDN may host generated siblings even when local disk does not.
        if (! is_file(public_path($path))) {
            return self::CDN_BASE.$candidate;
        }

        return null;
    }

    public static function variantUrl(?string $pathOrUrl, string $variant = 'md'): ?string
    {
        if (! $pathOrUrl) {
            return null;
        }

        $exact = self::exactVariantUrl($pathOrUrl, $variant);

        if ($exact) {
            return $exact;
        }

        $variant = strtolower($variant);

        if (! in_array($variant, ['xs', 'sm', 'md', 'lg'], true)) {
            $variant = 'md';
        }

        $path = self::toRelativePath($pathOrUrl);

        if (! $path) {
            return $pathOrUrl;
        }

        $original = $path;

        if (preg_match('/_(xs|sm|md|lg)(\.[a-zA-Z0-9]+)$/i', $path)) {
            $path = preg_replace('/_(xs|sm|md|lg)(\.[a-zA-Z0-9]+)$/i', '_'.$variant.'$2', $path);
        }

        // Order line snapshots are usually _xs-only; product thumbs live under img/thumb.
        if (preg_match('#^img/order/(.+)$#i', $path, $matches)) {
            $path = 'img/thumb/'.$matches[1];
        }

        if (is_file(public_path($path))) {
            return asset($path);
        }

        if ($path !== $original && is_file(public_path($original))) {
            return asset($original);
        }

        if (is_file(public_path($original))) {
            return asset($original);
        }

        return self::CDN_BASE.($path ?: $original);
    }

    public static function toRelativePath(string $pathOrUrl): ?string
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
