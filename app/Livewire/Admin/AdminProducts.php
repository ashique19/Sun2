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

    private const INLINE_FIELDS = ['price', 'purchase_price', 'commission', 'max_discount', 'stock_quantity'];

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $published = '';

    public ?int $editingProductId = null;

    public ?string $editingField = null;

    public string $editingValue = '';

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

    public function startInlineEdit(int $productId, string $field, string $value = ''): void
    {
        if (! in_array($field, self::INLINE_FIELDS, true)) {
            return;
        }

        Product::query()->findOrFail($productId);

        $this->editingProductId = $productId;
        $this->editingField = $field;
        $this->editingValue = $value;
        $this->resetValidation();
    }

    public function cancelInlineEdit(): void
    {
        $this->editingProductId = null;
        $this->editingField = null;
        $this->editingValue = '';
        $this->resetValidation();
    }

    public function saveInlineEdit(): void
    {
        if ($this->editingProductId === null || $this->editingField === null) {
            return;
        }

        $field = $this->editingField;

        if (! in_array($field, self::INLINE_FIELDS, true)) {
            $this->cancelInlineEdit();

            return;
        }

        $this->validate([
            'editingValue' => match ($field) {
                'price', 'commission' => ['required', 'numeric', 'min:0'],
                'purchase_price', 'max_discount' => ['nullable', 'numeric', 'min:0'],
                'stock_quantity' => ['required', 'integer', 'min:0'],
            },
        ]);

        $product = Product::query()->findOrFail($this->editingProductId);

        $value = match ($field) {
            'price', 'purchase_price', 'commission' => (int) round((float) ($this->editingValue === '' ? 0 : $this->editingValue)),
            'max_discount' => $this->editingValue === ''
                ? null
                : (int) round((float) $this->editingValue),
            'stock_quantity' => (int) $this->editingValue,
        };

        $product->update([$field => $value]);
        $this->cancelInlineEdit();
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
            ->paginate(50);

        return view('livewire.admin.admin-products', [
            'products' => $products,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
