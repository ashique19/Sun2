<?php

namespace App\Livewire\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Services\Admin\ProductImageService;
use App\Services\Admin\ProductPricedImageService;
use App\Services\Admin\ProductUnitCostService;
use App\Support\AdminAccess;
use App\Support\AdminProductListFilters;
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

    private const INLINE_FIELDS = ['price', 'compare_at_price', 'purchase_price', 'commission', 'max_discount', 'stock_quantity'];

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $published = '';

    #[Url]
    public string $priceMin = '';

    #[Url]
    public string $priceMax = '';

    /** @var list<int> */
    public array $selected = [];

    public bool $bulkStockOpen = false;

    public string $bulkStockQuantity = '';

    public ?int $editingProductId = null;

    public ?string $editingField = null;

    public string $editingValue = '';

    public ?string $message = null;

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

    public function updatedPriceMin(): void
    {
        $this->resetPage();
    }

    public function updatedPriceMax(): void
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

    public function toggleSelected(int $productId): void
    {
        $productId = (int) $productId;
        if ($productId <= 0) {
            return;
        }

        $this->selected = in_array($productId, $this->selected, true)
            ? array_values(array_diff($this->selected, [$productId]))
            : [...$this->selected, $productId];
    }

    public function clearSelected(): void
    {
        $this->selected = [];
        $this->closeBulkStock();
    }

    public function openBulkStock(): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->selected === []) {
            return;
        }

        $this->bulkStockOpen = true;
        $this->bulkStockQuantity = '';
        $this->resetValidation('bulkStockQuantity');
        $this->message = null;
    }

    public function closeBulkStock(): void
    {
        $this->bulkStockOpen = false;
        $this->bulkStockQuantity = '';
        $this->resetValidation('bulkStockQuantity');
    }

    public function applyBulkStock(): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->selected === []) {
            $this->closeBulkStock();

            return;
        }

        $this->validate([
            'bulkStockQuantity' => ['required', 'integer', 'min:0'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $this->selected)));
        $stock = (int) $this->bulkStockQuantity;

        $updated = Product::query()
            ->whereIn('id', $ids)
            ->update(['stock_quantity' => $stock]);

        $this->selected = [];
        $this->closeBulkStock();
        $this->message = $updated === 1
            ? 'Stock set to '.$stock.' for 1 product.'
            : 'Stock set to '.$stock.' for '.$updated.' products.';
    }

    public function makePost(): mixed
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->selected === []) {
            return null;
        }

        $ids = array_values(array_unique(array_map('intval', $this->selected)));
        $productsParam = implode(',', $ids);

        return redirect()->route('admin.social-posts.create', ['products' => $productsParam]);
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
                'compare_at_price' => ['nullable', 'numeric', 'min:0'],
                'purchase_price', 'max_discount' => ['nullable', 'numeric', 'min:0'],
                'stock_quantity' => ['required', 'integer', 'min:0'],
            },
        ]);

        $product = Product::query()->findOrFail($this->editingProductId);

        if ($field === 'compare_at_price') {
            $compareAtPrice = $this->editingValue === ''
                ? null
                : (int) round((float) $this->editingValue);

            if ($compareAtPrice !== null && $compareAtPrice <= (float) $product->price) {
                $this->addError('editingValue', 'Regular price must be greater than selling price.');

                return;
            }
        }

        $value = match ($field) {
            'price', 'purchase_price', 'commission' => (int) round((float) ($this->editingValue === '' ? 0 : $this->editingValue)),
            'compare_at_price' => $this->editingValue === ''
                ? null
                : (int) round((float) $this->editingValue),
            'max_discount' => $this->editingValue === ''
                ? null
                : (int) round((float) $this->editingValue),
            'stock_quantity' => (int) $this->editingValue,
        };

        $product->update([$field => $value]);

        if ($field === 'purchase_price') {
            app(ProductUnitCostService::class)->recalculate($product->fresh());
        }

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

    public function generatePricedImage(int $productId, ProductPricedImageService $pricedImages): void
    {
        $this->message = null;

        try {
            $product = Product::query()->findOrFail($productId);
            $pricedImages->generate($product);
            $this->message = 'Priced image created for “'.$product->name.'”.';
        } catch (\Throwable $e) {
            $this->addError('pricedImage', $e->getMessage());
        }
    }

    public function render()
    {
        $filters = $this->listFilters();
        $filters->remember();

        $products = $filters->apply(
            Product::query()->with(['category:id,name', 'images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
        )
            ->orderBy('display_order')
            ->orderByDesc('id')
            ->paginate(50);

        return view('livewire.admin.admin-products', [
            'products' => $products,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function listFilters(): AdminProductListFilters
    {
        return AdminProductListFilters::fromArray([
            'search' => $this->search,
            'category' => $this->category,
            'published' => $this->published,
            'priceMin' => $this->priceMin,
            'priceMax' => $this->priceMax,
        ]);
    }
}
