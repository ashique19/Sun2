<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Str;

class Seo
{
    public static function description(?string $value, ?string $fallback = null): string
    {
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $value))) ?: '');

        if ($text === '') {
            $text = $fallback ?? (string) config('seo.default_description');
        }

        return Str::limit($text, 160, '');
    }

    /**
     * Title used in WhatsApp / Messenger / Facebook link previews.
     * Format: "৳ 1,500 (Necklace, earring set…)"
     */
    public static function productShareTitle(Product $product): string
    {
        $price = '৳ '.number_format((float) $product->price, 0);
        $name = trim((string) $product->name);

        if ($name === '') {
            return $price;
        }

        return $price.' ('.$name.')';
    }

    public static function absoluteUrl(?string $pathOrUrl): string
    {
        if (! $pathOrUrl) {
            return url((string) config('seo.default_image'));
        }

        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }

        return url($pathOrUrl);
    }

    public static function robots(?string $override = null): string
    {
        if ($override !== null) {
            return $override;
        }

        $routeName = request()->route()?->getName();

        if ($routeName && in_array($routeName, config('seo.noindex_route_names', []), true)) {
            return 'noindex, nofollow';
        }

        return 'index, follow';
    }
}
