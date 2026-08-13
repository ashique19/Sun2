<?php

namespace App\Services\Admin;

use App\Models\Product;
use RuntimeException;

/**
 * Generate English + Bangla HTML product descriptions from the primary catalog image via Gemini.
 */
class ProductDescriptionGenerator
{
    private const ALLOWED_TAGS = '<p><br><br/><ul><ol><li><strong><b><em><i><u><a><h2><h3><h4><span>';

    public function __construct(
        private readonly GeminiClient $gemini,
    ) {}

    /**
     * @return array{description: string, description_bn: string}
     */
    public function generate(Product $product): array
    {
        if (! $this->gemini->isConfigured()) {
            throw new RuntimeException('Gemini API key is not configured (GEMINI_API_KEY).');
        }

        $image = $this->primaryImagePayload($product);

        if ($image === null) {
            throw new RuntimeException('Add a product image before generating descriptions.');
        }

        $name = trim((string) $product->name);
        $category = trim((string) ($product->category?->name ?? ''));
        $price = number_format((float) $product->price, 0);

        $system = <<<'PROMPT'
You write catalog copy for Sundoritoma, a Bangladeshi jewelry e-commerce store.
Return ONLY valid JSON with keys "description" (English) and "description_bn" (Bangla).
Each value must be HTML using only simple tags: p, ul, ol, li, strong, em, br.
Do not invent fake certifications, metals, gemstones, or dimensions you cannot see.
Describe what is visible in the photo: style, color, craftsmanship, and how it may be worn.
Keep each language to 2–4 short paragraphs or a short intro plus a bullet list.
Tone: warm, elegant, retail-friendly. No markdown fences.
PROMPT;

        $user = "Product name: {$name}\n"
            .'Category: '.($category !== '' ? $category : 'Jewelry')."\n"
            ."Catalog price (BDT): ৳{$price}\n"
            .'Write matching English and Bangla storefront descriptions from the product photo.';

        $json = $this->gemini->generateJsonFromParts($system, [
            ['text' => $user],
            [
                'inline_data' => [
                    'mime_type' => $image['mime'],
                    'data' => $image['base64'],
                ],
            ],
        ], [
            'temperature' => 0.4,
        ]);

        $en = $this->sanitizeHtml((string) ($json['description'] ?? $json['description_en'] ?? ''));
        $bn = $this->sanitizeHtml((string) ($json['description_bn'] ?? $json['description_bangla'] ?? ''));

        if ($en === '' && $bn === '') {
            throw new RuntimeException('Gemini returned empty descriptions.');
        }

        return [
            'description' => $en,
            'description_bn' => $bn,
        ];
    }

    /**
     * @return array{mime: string, base64: string}|null
     */
    private function primaryImagePayload(Product $product): ?array
    {
        $path = $product->primaryImagePath();

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $absolute = $this->resolveAbsoluteImagePath($path);

        if ($absolute === null) {
            return null;
        }

        $bytes = @file_get_contents($absolute);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        if (strlen($bytes) > 8 * 1024 * 1024) {
            throw new RuntimeException('Primary product image is larger than 8 MB.');
        }

        $mime = mime_content_type($absolute) ?: 'image/jpeg';

        if (! in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'], true)) {
            $mime = 'image/jpeg';
        }

        return [
            'mime' => $mime === 'image/jpg' ? 'image/jpeg' : $mime,
            'base64' => base64_encode($bytes),
        ];
    }

    private function resolveAbsoluteImagePath(string $path): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $path), '/');
        $candidates = [$relative];

        if (preg_match('/^(.*?)(_(?:lg|md|sm|xs))?\.jpe?g$/i', $relative, $matches) === 1) {
            $base = $matches[1];
            foreach (['md', 'lg', 'sm', 'xs'] as $variant) {
                $candidates[] = $base.'_'.$variant.'.jpg';
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            $absolute = public_path($candidate);

            if (is_file($absolute) && is_readable($absolute)) {
                return $absolute;
            }
        }

        return null;
    }

    public function sanitizeHtml(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = preg_replace('/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/\shref\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', ' href="#"', $html) ?? $html;

        return trim($html);
    }
}
