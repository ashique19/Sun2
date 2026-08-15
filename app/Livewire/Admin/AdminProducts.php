<?php

namespace App\Livewire\Admin;

use App\Models\AiPromptGroup;
use App\Models\Category;
use App\Models\Product;
use App\Services\Admin\GeminiClient;
use App\Services\Admin\ProductAiImageGenerator;
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

    public bool $bulkAiModalOpen = false;

    public bool $bulkAiRunning = false;

    public ?int $bulkAiPromptGroupId = null;

    public ?string $bulkAiMessage = null;

    /** @var list<array{id: int, name: string, thumb: ?string, status: string, message: ?string, steps_saved: int, step_total: int, step_current: int, progress: int}> */
    public array $bulkAiRows = [];

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

    public function openBulkAiGenerateModal(): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($this->selected === []) {
            $this->message = 'Select at least one product first.';

            return;
        }

        $this->closeBulkStock();
        $this->closeBulkCategory();
        $this->bulkAiModalOpen = true;
        $this->bulkAiRunning = false;
        $this->bulkAiPromptGroupId = null;
        $this->bulkAiMessage = null;
        $this->bulkAiRows = [];
        $this->resetValidation('bulkAiPromptGroupId');
        $this->message = null;
    }

    public function closeBulkAiGenerateModal(): void
    {
        if ($this->bulkAiRunning) {
            return;
        }

        $this->bulkAiModalOpen = false;
        $this->bulkAiRunning = false;
        $this->bulkAiPromptGroupId = null;
        $this->bulkAiMessage = null;
        $this->bulkAiRows = [];
        $this->resetValidation('bulkAiPromptGroupId');
    }

    public function startBulkAiGenerate(GeminiClient $gemini, ProductAiImageGenerator $generator): void
    {
        AdminAccess::ensureStaffAdmin();

        if (! $this->bulkAiModalOpen || $this->bulkAiRunning) {
            return;
        }

        if (! $gemini->isConfigured()) {
            $this->bulkAiMessage = 'Gemini API key is not configured (GEMINI_API_KEY).';

            return;
        }

        $this->validate([
            'bulkAiPromptGroupId' => ['required', 'integer', 'exists:ai_prompt_groups,id'],
        ], [], [
            'bulkAiPromptGroupId' => 'AI prompt sequence',
        ]);

        $group = AiPromptGroup::query()
            ->with(['prompts' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->findOrFail((int) $this->bulkAiPromptGroupId);

        $steps = $generator->normalizeSteps($group->stepTexts());

        if ($steps === []) {
            $this->addError('bulkAiPromptGroupId', 'This sequence has no usable prompt steps.');

            return;
        }

        $ids = array_values(array_unique(array_map('intval', $this->selected)));

        if ($ids === []) {
            $this->bulkAiMessage = 'Select at least one product first.';

            return;
        }

        $products = Product::query()
            ->with(['images' => fn ($q) => $q->where('is_admin_only', false)->orderByDesc('is_primary')->orderBy('sort_order')->limit(1)])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $this->bulkAiRows = [];

        foreach ($ids as $id) {
            $product = $products->get($id);

            if (! $product) {
                continue;
            }

            $this->bulkAiRows[] = [
                'id' => $product->id,
                'name' => $product->name,
                'thumb' => $product->images->first()?->path,
                'status' => 'pending',
                'message' => null,
                'steps_saved' => 0,
                'step_total' => count($steps),
                'step_current' => 0,
                'progress' => 0,
            ];
        }

        if ($this->bulkAiRows === []) {
            $this->bulkAiMessage = 'No selected products found.';

            return;
        }

        $this->bulkAiRunning = true;
        $this->bulkAiMessage = 'Running “'.$group->name.'” on '.count($this->bulkAiRows).' product(s)…';
        $this->queueNextBulkAiTick();
    }

    public function processNextBulkAiGenerate(ProductAiImageGenerator $generator): void
    {
        AdminAccess::ensureStaffAdmin();

        if (! $this->bulkAiModalOpen || ! $this->bulkAiRunning) {
            return;
        }

        $group = AiPromptGroup::query()
            ->with(['prompts' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->find((int) $this->bulkAiPromptGroupId);

        if (! $group) {
            $this->bulkAiRunning = false;
            $this->bulkAiMessage = 'The selected AI prompt sequence was removed.';

            return;
        }

        $steps = $generator->normalizeSteps($group->stepTexts());
        $activeIndex = null;

        foreach ($this->bulkAiRows as $index => $row) {
            if (($row['status'] ?? '') === 'generating') {
                $activeIndex = $index;
                break;
            }
        }

        if ($activeIndex === null) {
            foreach ($this->bulkAiRows as $index => $row) {
                if (($row['status'] ?? '') === 'pending') {
                    $activeIndex = $index;
                    break;
                }
            }
        }

        if ($activeIndex === null) {
            $this->finishBulkAiRun();

            return;
        }

        $row = $this->bulkAiRows[$activeIndex];
        $productId = (int) $row['id'];
        $product = Product::query()->with('images')->find($productId);

        if (! $product) {
            $this->bulkAiRows[$activeIndex] = $this->bulkAiRowFailed($row, 'Product was deleted.');
            $this->queueOrFinishBulkAi();

            return;
        }

        // First tick for this product: prepare source and paint progress before the long Gemini call.
        if (($row['status'] ?? '') === 'pending') {
            $prepared = $generator->prepareWorkingInput($product);

            if (($prepared['ok'] ?? false) !== true) {
                $this->bulkAiRows[$activeIndex] = $this->bulkAiRowFailed(
                    $row,
                    (string) ($prepared['error'] ?? 'Could not prepare source image.'),
                );
                $generator->forgetWorkingInput($product);
                $this->queueOrFinishBulkAi();

                return;
            }

            $stepTotal = max(1, count($steps));
            $this->bulkAiRows[$activeIndex] = [
                ...$row,
                'status' => 'generating',
                'step_total' => $stepTotal,
                'step_current' => 0,
                'steps_saved' => 0,
                'progress' => 4,
                'message' => 'Step 1 of '.$stepTotal.'…',
            ];

            $this->queueNextBulkAiTick();

            return;
        }

        $stepTotal = max(1, (int) ($row['step_total'] ?? count($steps)));
        $stepIndex = (int) ($row['step_current'] ?? 0);

        $result = $generator->generateStep($product, $steps, $stepIndex);

        if (($result['ok'] ?? false) !== true) {
            $generator->forgetWorkingInput($product);
            $this->bulkAiRows[$activeIndex] = $this->bulkAiRowFailed(
                $row,
                (string) ($result['error'] ?? 'AI generation failed.'),
            );
            $this->queueOrFinishBulkAi();

            return;
        }

        $stepsSaved = (int) ($row['steps_saved'] ?? 0) + 1;
        $nextStepIndex = $stepIndex + 1;
        $progress = (int) round(($nextStepIndex / $stepTotal) * 100);

        if ($nextStepIndex >= $stepTotal) {
            $generator->forgetWorkingInput($product);
            $this->bulkAiRows[$activeIndex] = [
                ...$row,
                'status' => 'success',
                'step_current' => $stepTotal,
                'steps_saved' => $stepsSaved,
                'progress' => 100,
                'message' => $stepsSaved === 1
                    ? 'Saved 1 admin-only image'
                    : "Saved {$stepsSaved} admin-only images",
            ];
            $this->selected = array_values(array_diff($this->selected, [$productId]));
            $this->queueOrFinishBulkAi();

            return;
        }

        $this->bulkAiRows[$activeIndex] = [
            ...$row,
            'status' => 'generating',
            'step_current' => $nextStepIndex,
            'steps_saved' => $stepsSaved,
            'progress' => max(4, $progress),
            'message' => 'Step '.($nextStepIndex + 1).' of '.$stepTotal.'…',
        ];

        $this->queueNextBulkAiTick();
    }

    /**
     * @param  array{id: int, name: string, thumb: ?string, status: string, message: ?string, steps_saved: int, step_total: int, step_current: int, progress: int}  $row
     * @return array{id: int, name: string, thumb: ?string, status: string, message: ?string, steps_saved: int, step_total: int, step_current: int, progress: int}
     */
    private function bulkAiRowFailed(array $row, string $message): array
    {
        return [
            ...$row,
            'status' => 'failed',
            'message' => $message,
            'progress' => (int) ($row['progress'] ?? 0),
        ];
    }

    private function queueOrFinishBulkAi(): void
    {
        $hasMore = collect($this->bulkAiRows)->contains(
            fn (array $row) => in_array($row['status'] ?? '', ['pending', 'generating'], true),
        );

        if ($hasMore) {
            $this->queueNextBulkAiTick();

            return;
        }

        $this->finishBulkAiRun();
    }

    private function queueNextBulkAiTick(): void
    {
        // Unit tests drive ticks explicitly so intermediate progress can be asserted.
        if (app()->runningUnitTests()) {
            return;
        }

        $this->js('setTimeout(() => $wire.processNextBulkAiGenerate(), 50)');
    }

    private function finishBulkAiRun(): void
    {
        $this->bulkAiRunning = false;
        $success = collect($this->bulkAiRows)->where('status', 'success')->count();
        $failed = collect($this->bulkAiRows)->where('status', 'failed')->count();
        $this->bulkAiMessage = "Finished. {$success} succeeded, {$failed} failed.";
        $this->message = $this->bulkAiMessage;
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

        $aiPromptGroups = $this->bulkAiModalOpen
            ? AiPromptGroup::query()
                ->withCount('prompts')
                ->with(['prompts' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
            : collect();

        return view('livewire.admin.admin-products', [
            'products' => $products,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'aiPromptGroups' => $aiPromptGroups,
            'geminiConfigured' => app(GeminiClient::class)->isConfigured(),
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
