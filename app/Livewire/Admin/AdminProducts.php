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

    private const PUT_PRICE_BATCH_SIZE = 10;

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

    public bool $bulkCategoryOpen = false;

    public string $bulkCategoryId = '';

    public ?int $editingProductId = null;

    public ?string $editingField = null;

    public string $editingValue = '';

    public ?string $message = null;

    public bool $putPriceModalOpen = false;

    /** When true, process selected products and replace existing priced images. */
    public bool $putPriceReplaceExisting = false;

    /** @var list<int> Product IDs still waiting in selected/replace mode. */
    public array $putPricePendingIds = [];

    /** @var list<array{id: int, name: string, price: string, thumb: ?string}> */
    public array $putPriceBatch = [];

    public int $putPriceRemaining = 0;

    public int $putPriceTotalSaved = 0;

    public bool $putPriceRunning = false;

    public ?string $putPriceMessage = null;

    /** @var list<string> */
    public array $putPriceErrors = [];

    /** @var list<int> */
    public array $putPriceSkippedIds = [];

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
        $this->closeBulkCategory();
    }

    public function openBulkStock(): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->selected === []) {
            return;
        }

        $this->closeBulkCategory();
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

    public function openBulkCategory(): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->selected === []) {
            return;
        }

        $this->closeBulkStock();
        $this->bulkCategoryOpen = true;
        $this->bulkCategoryId = '';
        $this->resetValidation('bulkCategoryId');
        $this->message = null;
    }

    public function closeBulkCategory(): void
    {
        $this->bulkCategoryOpen = false;
        $this->bulkCategoryId = '';
        $this->resetValidation('bulkCategoryId');
    }

    public function applyBulkCategory(): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->selected === []) {
            $this->closeBulkCategory();

            return;
        }

        $this->validate([
            'bulkCategoryId' => ['required', 'integer', 'exists:categories,id'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $this->selected)));
        $categoryId = (int) $this->bulkCategoryId;
        $category = Category::query()->findOrFail($categoryId);

        $updated = Product::query()
            ->whereIn('id', $ids)
            ->update(['category_id' => $categoryId]);

        $this->selected = [];
        $this->closeBulkCategory();
        $this->message = $updated === 1
            ? 'Category set to “'.$category->name.'” for 1 product.'
            : 'Category set to “'.$category->name.'” for '.$updated.' products.';
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

    public function openPutPriceModal(): void
    {
        $this->putPriceModalOpen = true;
        $this->putPriceMessage = null;
        $this->putPriceErrors = [];
        $this->putPriceSkippedIds = [];
        $this->putPriceTotalSaved = 0;
        $this->putPriceRunning = false;
        $this->putPriceReplaceExisting = $this->selected !== [];
        $this->putPricePendingIds = $this->putPriceReplaceExisting
            ? Product::query()
                ->whereIn('id', array_values(array_unique(array_map('intval', $this->selected))))
                ->whereHas('images')
                ->orderByDesc('id')
                ->pluck('id')
                ->all()
            : [];
        $this->refreshPutPriceBatch();
        $this->js('document.body.classList.add("overflow-hidden")');
    }

    public function closePutPriceModal(): void
    {
        $this->putPriceModalOpen = false;
        $this->putPriceBatch = [];
        $this->putPriceRemaining = 0;
        $this->putPriceTotalSaved = 0;
        $this->putPriceRunning = false;
        $this->putPriceReplaceExisting = false;
        $this->putPricePendingIds = [];
        $this->putPriceMessage = null;
        $this->putPriceErrors = [];
        $this->putPriceSkippedIds = [];
        $this->js('document.body.classList.remove("overflow-hidden")');
    }

    public function applyPutPriceBatch(ProductPricedImageService $pricedImages): void
    {
        if (! $this->putPriceModalOpen || $this->putPriceBatch === []) {
            $this->putPriceRunning = false;

            return;
        }

        $this->putPriceRunning = true;
        $this->putPriceErrors = [];
        $saved = 0;
        $batchIds = [];

        foreach ($this->putPriceBatch as $row) {
            $batchIds[] = $row['id'];
            $product = Product::query()->find($row['id']);

            if (! $product) {
                continue;
            }

            // Missing-only mode: skip products that already have a priced image.
            if (! $this->putPriceReplaceExisting && filled($product->priced_image_path)) {
                continue;
            }

            try {
                $sourcePath = $product->primaryImagePath();

                if (! $sourcePath) {
                    throw new \RuntimeException('A primary product image is required first.');
                }

                $layout = $pricedImages->autoFillLayout(
                    public_path(ltrim($sourcePath, '/')),
                    $product,
                );
                $pricedImages->generate($product, $layout);
                $saved++;
            } catch (\Throwable $e) {
                $this->putPriceSkippedIds[] = $product->id;
                $this->putPriceErrors[] = $product->name.': '.$e->getMessage();
            }
        }

        if ($this->putPriceReplaceExisting) {
            $this->putPricePendingIds = array_values(array_diff($this->putPricePendingIds, $batchIds));
        }

        $this->putPriceTotalSaved += $saved;
        $this->refreshPutPriceBatch();

        if ($this->putPriceBatch === []) {
            $this->putPriceRunning = false;
            $this->putPriceMessage = $this->putPriceCompletionMessage();

            return;
        }

        $this->putPriceMessage = $this->putPriceProgressMessage();

        // Keep going through the next 10 without another click.
        if (app()->runningUnitTests()) {
            $this->applyPutPriceBatch($pricedImages);

            return;
        }

        $this->js('setTimeout(() => $wire.applyPutPriceBatch(), 50)');
    }

    private function putPriceCompletionMessage(): string
    {
        if ($this->putPriceReplaceExisting) {
            return $this->putPriceTotalSaved === 1
                ? 'Saved 1 priced image for the selection.'
                : 'Saved '.$this->putPriceTotalSaved.' priced images for the selection.';
        }

        return $this->putPriceTotalSaved === 1
            ? 'Saved 1 priced image. All products with photos now have one.'
            : 'Saved '.$this->putPriceTotalSaved.' priced images. All products with photos now have one.';
    }

    private function putPriceProgressMessage(): string
    {
        if ($this->putPriceReplaceExisting) {
            return 'Saved '.$this->putPriceTotalSaved.'. '
                .$this->putPriceRemaining.' selected left…';
        }

        return 'Saved '.$this->putPriceTotalSaved.'. '
            .$this->putPriceRemaining.' still need a priced image…';
    }

    private function refreshPutPriceBatch(): void
    {
        if ($this->putPriceReplaceExisting) {
            $this->refreshSelectedPutPriceBatch();

            return;
        }

        $query = Product::query()
            ->where(function ($q) {
                $q->whereNull('priced_image_path')
                    ->orWhere('priced_image_path', '');
            })
            ->whereHas('images')
            ->when($this->putPriceSkippedIds !== [], function ($q) {
                $q->whereNotIn('id', $this->putPriceSkippedIds);
            });

        $this->putPriceRemaining = (clone $query)->count();

        $this->putPriceBatch = $query
            ->with(['images' => fn ($q) => $q->where('is_admin_only', false)->orderBy('sort_order')->limit(1)])
            ->orderByDesc('id')
            ->limit(self::PUT_PRICE_BATCH_SIZE)
            ->get()
            ->map(fn (Product $product) => $this->putPriceBatchRow($product))
            ->all();
    }

    private function refreshSelectedPutPriceBatch(): void
    {
        $pendingIds = array_values(array_diff($this->putPricePendingIds, $this->putPriceSkippedIds));
        $this->putPricePendingIds = $pendingIds;
        $this->putPriceRemaining = count($pendingIds);

        $batchIds = array_slice($pendingIds, 0, self::PUT_PRICE_BATCH_SIZE);

        if ($batchIds === []) {
            $this->putPriceBatch = [];

            return;
        }

        $products = Product::query()
            ->whereIn('id', $batchIds)
            ->with(['images' => fn ($q) => $q->where('is_admin_only', false)->orderBy('sort_order')->limit(1)])
            ->get()
            ->keyBy('id');

        $this->putPriceBatch = collect($batchIds)
            ->map(fn (int $id) => $products->get($id))
            ->filter()
            ->map(fn (Product $product) => $this->putPriceBatchRow($product))
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, price: string, thumb: ?string}
     */
    private function putPriceBatchRow(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'price' => number_format((float) $product->price, 0),
            'thumb' => $product->images->first()?->path,
        ];
    }

    public function render()
    {
        $filters = $this->listFilters();
        $filters->remember();

        $products = $filters->apply(
            Product::query()->with(['category:id,name', 'images' => fn ($q) => $q->where('is_admin_only', false)->orderBy('sort_order')->limit(1)])
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
