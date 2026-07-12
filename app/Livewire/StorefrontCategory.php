<?php

namespace App\Livewire;

use App\Models\Category;
use App\Models\Product;
use App\Support\Seo;
use App\Support\StorefrontAssets;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class StorefrontCategory extends Component
{
    use WithPagination;

    public Category $category;

    #[Url(as: 'sort')]
    public string $sort = 'featured';

    public function mount(Category $category): void
    {
        abort_unless($category->is_active, 404);
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function title(): string
    {
        return $this->category->name.' - Sundoritoma';
    }

    public function render()
    {
        $products = Product::query()
            ->with([
                'category:id,name,slug',
                'listingImage',
            ])
            ->published()
            ->where('category_id', $this->category->id)
            ->when($this->sort === 'price_asc', fn ($q) => $q->orderBy('price'))
            ->when($this->sort === 'price_desc', fn ($q) => $q->orderByDesc('price'))
            ->when($this->sort === 'newest', fn ($q) => $q->orderByDesc('id'))
            ->when($this->sort === 'featured', fn ($q) => $q->orderBy('display_order')->orderByDesc('id'))
            ->paginate(24);

        return view('livewire.storefront-category', [
            'products' => $products,
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
            ]);
    }
}
