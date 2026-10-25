<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Product;
use App\Support\Seo;
use App\Support\StorefrontAssets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app')]
class StorefrontCategory extends Component
{
    public const PAGE_SIZE = 24;

    public Category $category;

    public int $perPage = self::PAGE_SIZE;

    #[Url(as: 'sort')]
    public string $sort = 'featured';

    #[Url(as: 'stock')]
    public bool $inStockOnly = false;

    public function mount(Category $category): void
    {
        abort_unless($category->is_active, 404);
    }

    public function updatedSort(): void
    {
        $this->perPage = self::PAGE_SIZE;
    }

    public function updatedInStockOnly(): void
    {
        $this->perPage = self::PAGE_SIZE;
    }

    public function loadMore(): void
    {
        if (! $this->hasMoreProducts()) {
            return;
        }

        $this->perPage += self::PAGE_SIZE;
    }

    public function title(): string
    {
        return $this->category->name.' - Sundoritoma';
    }

    public function render()
    {
        [$products, $hasMore] = $this->visibleProducts();

        return view('livewire.storefront-category', [
            'products' => $products,
            'hasMore' => $hasMore,
        ])
            ->title($this->title())
            ->layoutData([
                'seoDescription' => Seo::description(
                    $this->category->summary ?: $this->category->headline,
                    'Shop '.$this->category->name.' — high-quality handmade jewellery from Sundoritoma. Cash on delivery and home delivery all over Bangladesh.',
                ),
                'seoCanonical' => route('category.show', $this->category),
                'seoImage' => StorefrontAssets::url($this->category->thumb_image),
                'seoType' => 'website',
                // Faceted/sort URLs keep a clean canonical; keep them out of the index.
                'seoRobots' => ($this->sort !== 'featured' || $this->inStockOnly)
                    ? 'noindex, follow'
                    : null,
            ]);
    }

    /**
     * @return array{0: Collection<int, Product>, 1: bool}
     */
    private function visibleProducts(): array
    {
        $products = $this->categoryProductQuery()
            ->limit($this->perPage + 1)
            ->get();

        $hasMore = $products->count() > $this->perPage;

        return [$products->take($this->perPage)->values(), $hasMore];
    }

    private function hasMoreProducts(): bool
    {
        return $this->categoryProductQuery()
            ->skip($this->perPage)
            ->take(1)
            ->exists();
    }

    /**
     * @return Builder<Product>
     */
    private function categoryProductQuery(): Builder
    {
        return Product::query()
            ->with([
                'category:id,name,slug',
                'listingImage',
            ])
            ->published()
            ->where('category_id', $this->category->id)
            ->when($this->inStockOnly, fn (Builder $q) => $q->where('stock_quantity', '>', 0))
            ->when($this->sort === 'price_asc', fn (Builder $q) => $q->orderBy('price'))
            ->when($this->sort === 'price_desc', fn (Builder $q) => $q->orderByDesc('price'))
            ->when($this->sort === 'newest', fn (Builder $q) => $q->orderByDesc('id'))
            ->when($this->sort === 'featured', fn (Builder $q) => $q->orderBy('display_order')->orderByDesc('id'));
    }
}
