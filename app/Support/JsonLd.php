<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;

class JsonLd
{
    public static function organization(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => config('seo.site_name'),
            'url' => url('/'),
            'logo' => Seo::absoluteUrl('/img/settings/logo.png'),
            'email' => config('seo.organization.email'),
            'telephone' => config('seo.organization.telephone'),
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => config('seo.organization.address_locality'),
                'addressCountry' => config('seo.organization.address_country'),
            ],
            'description' => config('seo.default_description'),
        ];
    }

    /**
     * Sitewide WebSite entity (no SearchAction: /search is noindex + robots-disallowed).
     */
    public static function website(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => config('seo.site_name'),
            'url' => url('/'),
            'description' => config('seo.default_description'),
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('seo.site_name'),
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => Seo::absoluteUrl('/img/settings/logo.png'),
                ],
            ],
        ];
    }

    public static function product(Product $product): array
    {
        $images = $product->images
            ->map(fn ($image) => StorefrontAssets::url($image->path))
            ->filter()
            ->values()
            ->all();

        if ($images === []) {
            $primary = StorefrontAssets::url($product->primaryImagePath());
            if ($primary) {
                $images = [$primary];
            }
        }

        $description = Seo::description(
            $product->meta_description ?: $product->description,
            $product->name.' — high-quality handmade jewellery from Sundoritoma.',
        );

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => $description,
            'sku' => $product->sku ?: (string) $product->id,
            'url' => route('product.show', $product),
            'brand' => [
                '@type' => 'Brand',
                'name' => config('seo.site_name'),
            ],
            'offers' => [
                '@type' => 'Offer',
                'url' => route('product.show', $product),
                'priceCurrency' => 'BDT',
                'price' => number_format((float) $product->price, 2, '.', ''),
                'availability' => $product->isInStock()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
                'seller' => [
                    '@type' => 'Organization',
                    'name' => config('seo.site_name'),
                ],
            ],
        ];

        if ($images !== []) {
            $data['image'] = $images;
        }

        if ($product->category) {
            $data['category'] = $product->category->name;
        }

        if ($product->review_count > 0 && $product->rating_avg !== null) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format((float) $product->rating_avg, 1, '.', ''),
                'reviewCount' => (int) $product->review_count,
                'bestRating' => '5',
                'worstRating' => '1',
            ];
        }

        return $data;
    }

    /**
     * @param  list<array{name: string, url?: string|null}>  $items
     */
    public static function breadcrumb(array $items): array
    {
        $list = [];

        foreach (array_values($items) as $index => $item) {
            $entry = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
            ];

            if (! empty($item['url'])) {
                $entry['item'] = $item['url'];
            }

            $list[] = $entry;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $list,
        ];
    }

    public static function categoryBreadcrumb(Category $category): array
    {
        return self::breadcrumb([
            ['name' => 'Home', 'url' => route('home')],
            ['name' => $category->name, 'url' => route('category.show', $category)],
        ]);
    }

    public static function productBreadcrumb(Product $product): array
    {
        $items = [
            ['name' => 'Home', 'url' => route('home')],
        ];

        if ($product->category) {
            $items[] = [
                'name' => $product->category->name,
                'url' => route('category.show', $product->category),
            ];
        }

        $items[] = [
            'name' => $product->name,
            'url' => route('product.show', $product),
        ];

        return self::breadcrumb($items);
    }
}
