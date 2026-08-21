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
     * Sitewide WebSite entity. SearchAction targets the indexable /shop hub
     * (query/facet URLs on /shop stay noindex; legacy /search remains robots-disallowed).
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
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => route('shop').'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    public static function product(Product $product): array
    {
        $images = $product->images
            ->where('is_admin_only', false)
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
            $product->meta_description ?: ProductDescriptionHtml::forStorefront($product),
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

        $reviews = self::approvedReviewsForSchema($product);

        if ($reviews !== []) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format((float) $product->rating_avg, 1, '.', ''),
                'reviewCount' => max((int) $product->review_count, count($reviews)),
                'bestRating' => '5',
                'worstRating' => '1',
            ];
            $data['review'] = $reviews;
        } elseif ($product->review_count > 0 && $product->rating_avg !== null) {
            // Aggregates exist but review rows were not loaded / unavailable.
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
     * @return list<array<string, mixed>>
     */
    private static function approvedReviewsForSchema(Product $product): array
    {
        if ((int) $product->review_count <= 0) {
            return [];
        }

        if (! $product->relationLoaded('approvedReviews')) {
            $product->load(['approvedReviews.user']);
        } elseif ($product->approvedReviews->isNotEmpty()
            && ! $product->approvedReviews->first()?->relationLoaded('user')) {
            $product->approvedReviews->load('user');
        }

        return $product->approvedReviews
            ->filter(fn ($review) => filled($review->body) && (int) $review->rating >= 1)
            ->take(10)
            ->map(function ($review): array {
                $entry = [
                    '@type' => 'Review',
                    'author' => [
                        '@type' => 'Person',
                        'name' => filled($review->user?->name)
                            ? (string) $review->user->name
                            : 'Customer',
                    ],
                    'reviewRating' => [
                        '@type' => 'Rating',
                        'ratingValue' => (string) (int) $review->rating,
                        'bestRating' => '5',
                        'worstRating' => '1',
                    ],
                    'reviewBody' => (string) $review->body,
                ];

                if ($review->created_at) {
                    $entry['datePublished'] = $review->created_at->toDateString();
                }

                if (filled($review->title)) {
                    $entry['name'] = (string) $review->title;
                }

                return $entry;
            })
            ->values()
            ->all();
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
