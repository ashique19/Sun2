<?php

namespace App\Support;

use App\Models\Product;

/**
 * Product body copy is stored as a small HTML subset (legacy + Gemini).
 * Sanitize on write and on storefront render; never show raw tags in admin.
 */
class ProductDescriptionHtml
{
    public const ALLOWED_TAGS = '<p><br><br/><ul><ol><li><strong><b><em><i><u><a><h2><h3><h4><span><div>';

    public static function sanitize(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = preg_replace('/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/\shref\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', ' href="#"', $html) ?? $html;

        return trim($html);
    }

    /**
     * Prefer Bangla body copy when present (storefront default).
     */
    public static function forStorefront(Product $product): string
    {
        $bn = self::sanitize($product->description_bn);
        if ($bn !== '') {
            return $bn;
        }

        return self::sanitize($product->description);
    }
}
