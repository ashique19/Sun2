<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Services\Sitemap\SitemapRebuildService;

class SitemapInvalidationObserver
{
    public function __construct(
        private readonly SitemapRebuildService $sitemaps,
    ) {}

    public function saved(Product|Category|Page $model): void
    {
        if ($model instanceof Product && ! $this->productAffectsSitemap($model)) {
            return;
        }

        if ($model instanceof Category && ! $this->categoryAffectsSitemap($model)) {
            return;
        }

        $this->sitemaps->requestAutoRebuild(class_basename($model).'#'.$model->getKey().' saved');
    }

    public function deleted(Product|Category|Page $model): void
    {
        $this->sitemaps->requestAutoRebuild(class_basename($model).'#'.$model->getKey().' deleted');
    }

    private function productAffectsSitemap(Product $product): bool
    {
        if ($product->wasRecentlyCreated) {
            return (bool) $product->is_published;
        }

        return $product->wasChanged(['slug', 'is_published']);
    }

    private function categoryAffectsSitemap(Category $category): bool
    {
        if ($category->wasRecentlyCreated) {
            return (bool) $category->is_active;
        }

        return $category->wasChanged(['slug', 'is_active']);
    }
}
