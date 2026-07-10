<?php

namespace App\Livewire;

use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class StorefrontSearch extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $q = '';

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function title(): string
    {
        return $this->q !== ''
            ? 'Search: '.$this->q.' - Sundoritoma'
            : 'Search - Sundoritoma';
    }

    public function render()
    {
        $products = Product::query()
            ->with([
                'category:id,name,slug',
                'listingImage',
            ])
            ->published()
            ->searchTerm($this->q)
            ->orderBy('display_order')
            ->orderByDesc('id')
            ->paginate(24);

        return view('livewire.storefront-search', [
            'products' => $products,
        ])->title($this->title());
    }
}
