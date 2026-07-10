<?php

namespace App\Livewire\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Services\Admin\ProductImageService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Products')]
#[Layout('components.layouts.admin')]
class AdminProducts extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $published = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedPublished(): void
    {
        $this->resetPage();
    }

    public function togglePublished(int $productId): void
    {
        $product = Product::query()->findOrFail($productId);
        $product->update(['is_published' => ! $product->is_published]);
    }

    public function delete(int $productId, ProductImageService $images): void
    {
        $product = Product::query()->findOrFail($productId);
        $images->deleteProduct($product);
    }

    public function render()
    {
        $products = Product::query()
            ->with(['category:id,name', 'images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
            ->when($this->search !== '', fn ($q) => $q->searchTerm($this->search))
            ->when($this->category !== '', fn ($q) => $q->where('category_id', $this->category))
            ->when($this->published === '1', fn ($q) => $q->where('is_published', true))
            ->when($this->published === '0', fn ($q) => $q->where('is_published', false))
            ->orderBy('display_order')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.admin.admin-products', [
            'products' => $products,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
