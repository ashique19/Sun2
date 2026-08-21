<?php

namespace App\Livewire;

use App\Models\Product;
use App\Support\Seo;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class StorefrontShop extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $q = '';

    #[Url(as: 'sort')]
    public string $sort = 'featured';

    #[Url(as: 'stock')]
    public bool $inStockOnly = false;

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedInStockOnly(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->q = '';
        $this->inStockOnly = false;
        $this->sort = 'featured';
        $this->resetPage();
    }

    public function title(): string
    {
        if ($this->q !== '') {
            return 'Search: '.$this->q.' - Sundoritoma';
        }

        return 'Shop - Sundoritoma';
    }

    public function render()
    {
        $products = Product::query()
            ->with([
                'category:id,name,slug',
                'listingImage',
            ])
            ->published()
            ->when($this->q !== '', fn ($q) => $q->searchTerm($this->q))
            ->when($this->inStockOnly, fn ($q) => $q->where('stock_quantity', '>', 0))
            ->when($this->sort === 'price_asc', fn ($q) => $q->orderBy('price'))
            ->when($this->sort === 'price_desc', fn ($q) => $q->orderByDesc('price'))
            ->when($this->sort === 'newest', fn ($q) => $q->orderByDesc('id'))
            ->when($this->sort === 'featured', fn ($q) => $q->orderBy('display_order')->orderByDesc('id'))
            ->paginate(24);

        $isFaceted = $this->q !== '' || $this->sort !== 'featured' || $this->inStockOnly;

        return view('livewire.storefront-shop', [
            'products' => $products,
        ])
            ->title($this->title())
            ->layoutData([
                'seoDescription' => Seo::description(
                    $this->q !== ''
                        ? 'Search results for “'.$this->q.'” at Sundoritoma.'
                        : 'Browse high-quality handmade jewellery from Sundoritoma. Cash on delivery and home delivery all over Bangladesh.',
                ),
                'seoCanonical' => route('shop'),
                'seoType' => 'website',
                'seoRobots' => $isFaceted ? 'noindex, follow' : null,
            ]);
    }
}
