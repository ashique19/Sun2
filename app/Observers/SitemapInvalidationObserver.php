<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Sitemap\SitemapRebuildService;

class SitemapInvalidationObserver
{
    public function __construct(
        private readonly SitemapRebuildService $sitemaps,
    ) {}

    public function saved(Product|Category|Page|ProductImage $model): void
    {
        if ($model instanceof ProductImage) {
            if ($this->productImageAffectsSitemap($model)) {
                $this->sitemaps->requestAutoRebuild('ProductImage#'.$model->getKey().' saved');
            }

            return;
        }

        if ($model instanceof Product && ! $this->productAffectsSitemap($model)) {
            return;
        }

        if ($model instanceof Category && ! $this->categoryAffectsSitemap($model)) {
            return;
        }

        $this->sitemaps->requestAutoRebuild(class_basename($model).'#'.$model->getKey().' saved');
    }

    public function deleted(Product|Category|Page|ProductImage $model): void
    {
        if ($model instanceof ProductImage) {
            if ($this->productImageAffectsSitemap($model, deleting: true)) {
                $this->sitemaps->requestAutoRebuild('ProductImage#'.$model->getKey().' deleted');
            }

            return;
        }

        $this->sitemaps->requestAutoRebuild(class_basename($model).'#'.$model->getKey().' deleted');
    }

    private function productAffectsSitemap(Product $product): bool
    {
        if ($product->wasRecentlyCreated) {
            return (bool) $product->is_published;
        }

        // URL membership + lastmod/title fields (skip stock/ratings churn).
        return $product->wasChanged([
            'slug',
            'is_published',
            'name',
            'price',
            'category_id',
            'meta_title',
            'meta_description',
        ]);
    }

    private function categoryAffectsSitemap(Category $category): bool
    {
        if ($category->wasRecentlyCreated) {
            return (bool) $category->is_active;
        }

        return $category->wasChanged([
            'slug',
            'is_active',
            'name',
            'headline',
            'summary',
        ]);
    }

    private function productImageAffectsSitemap(ProductImage $image, bool $deleting = false): bool
    {
        $product = $image->relationLoaded('product')
            ? $image->product
            : Product::query()->find($image->product_id);

        if (! $product?->is_published) {
            return false;
        }

        if ($deleting || $image->wasRecentlyCreated) {
            return true;
        }

        return $image->wasChanged(['path', 'is_primary', 'sort_order', 'alt']);
    }
}
