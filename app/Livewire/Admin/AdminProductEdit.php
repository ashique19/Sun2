<?php

namespace App\Livewire\Admin;

use App\Models\AiImagePrompt;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\GeminiClient;
use App\Services\Admin\ProductImageService;
use App\Services\Admin\ProductPricedImageService;
use App\Support\Fileinfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

#[Layout('components.layouts.admin')]
class AdminProductEdit extends Component
{
    use WithFileUploads;

    public ?Product $product = null;

    public ?int $category_id = null;

    public string $name = '';

    public string $slug = '';

    public string $sku = '';

    public string $description = '';

    public string $price = '0';

    public string $purchase_price = '0';

    public string $commission = '0';

    public string $max_discount = '';

    public string $compare_at_price = '';

    public int $stock_quantity = 0;

    public int $display_order = 0;

    public bool $is_published = false;

    public bool $is_featured = false;

    /** @var array<int, TemporaryUploadedFile> */
    public array $newImages = [];

    /** @var array<int, string> */
    public array $pendingAlts = [];

    /** @var array<int, string> */
    public array $imageAlts = [];

    public ?string $message = null;

    /** Set by ensureProductSaved() so uploadImages can redirect after create. */
    public bool $justCreated = false;

    public bool $showAiGenerateModal = false;

    public string $aiPrompt = '';

    /** @var TemporaryUploadedFile|null */
    public $aiRawImage = null;

    /** @var list<array{id: string, mime: string, base64: string, name: string}> */
    public array $aiCandidates = [];

    public ?string $aiGenerateError = null;

    public bool $aiGenerating = false;

    public bool $showPricedImageModal = false;

    public int $pricedImageX = 24;

    public int $pricedImageY = 24;

    public int $pricedImageFont = 5;

    public function mount(?Product $product = null): void
    {
        if (! $product?->exists) {
            return;
        }

        $this->product = $product->load(['images' => fn ($q) => $q->orderBy('sort_order')]);
        $this->imageAlts = $this->product->images
            ->mapWithKeys(fn (ProductImage $image) => [$image->id => (string) ($image->alt ?? '')])
            ->all();
        $this->category_id = $product->category_id;
        $this->name = $product->name;
        $this->slug = $product->slug;
        $this->sku = (string) ($product->sku ?? '');
        $this->description = (string) ($product->description ?? '');
        $this->price = (string) (int) round((float) $product->price);
        $this->purchase_price = (string) (int) round((float) $product->purchase_price);
        $this->commission = (string) (int) round((float) $product->commission);
        $this->max_discount = $product->max_discount !== null
            ? (string) (int) round((float) $product->max_discount)
            : '';
        $this->compare_at_price = $product->compare_at_price !== null
            ? (string) (int) round((float) $product->compare_at_price)
            : '';
        $this->stock_quantity = (int) $product->stock_quantity;
        $this->display_order = (int) $product->display_order;
        $this->is_published = (bool) $product->is_published;
        $this->is_featured = (bool) $product->is_featured;
        $this->fillPricedImageLayout();
    }

    public function title(): string
    {
        return $this->product ? 'Edit '.$this->product->name : 'Create Product';
    }

    public function updatedName(string $value): void
    {
        if ($this->product) {
            return;
        }

        $this->slug = Str::slug($value);
    }

    public function openAiGenerateModal(): void
    {
        $this->aiGenerateError = null;
        $this->ensureProductSaved();

        if ($this->justCreated) {
            $this->justCreated = false;
        }

        $this->showAiGenerateModal = true;
    }

    public function closeAiGenerateModal(): void
    {
        $this->showAiGenerateModal = false;
        $this->aiGenerateError = null;
        $this->aiGenerating = false;
        $this->aiRawImage = null;
        $this->aiCandidates = [];
        $this->resetValidation(['aiRawImage', 'aiPrompt']);
    }

    public function useRecentPrompt(string $prompt): void
    {
        $this->aiPrompt = $prompt;
    }

    public function openPricedImageModal(ProductPricedImageService $pricedImages): void
    {
        $this->ensureProductSaved();
        $this->fillPricedImageLayout($pricedImages);
        $this->showPricedImageModal = true;
    }

    public function closePricedImageModal(): void
    {
        $this->showPricedImageModal = false;
    }

    public function savePricedImageLayout(): void
    {
        $this->validate([
            'pricedImageX' => ['required', 'integer', 'min:0'],
            'pricedImageY' => ['required', 'integer', 'min:0'],
            'pricedImageFont' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        if (! $this->product) {
            $this->ensureProductSaved();
        }

        $this->product->update([
            'priced_image_layout' => [
                'x' => $this->pricedImageX,
                'y' => $this->pricedImageY,
                'font' => $this->pricedImageFont,
            ],
        ]);
    }

    public function generatePricedImage(ProductPricedImageService $pricedImages): void
    {
        if (! $this->product) {
            $this->ensureProductSaved();
        }

        $this->savePricedImageLayout();
        $pricedImages->generate($this->product->fresh(), [
            'x' => $this->pricedImageX,
            'y' => $this->pricedImageY,
            'font' => $this->pricedImageFont,
        ]);
        $this->product->refresh();
        $this->message = 'Priced image generated.';
    }

    public function generateAiImage(GeminiClient $gemini): void
    {
        $this->aiGenerateError = null;
        $this->aiGenerating = true;

        try {
            if (! $this->product) {
                $this->ensureProductSaved();
            }

            $this->validate([
                'aiRawImage' => Fileinfo::storedImageRules(8192, true),
                'aiPrompt' => ['required', 'string', 'min:3', 'max:4000'],
            ]);

            if (! $gemini->isConfigured()) {
                throw new RuntimeException('Gemini API key is not configured (GEMINI_API_KEY).');
            }

            /** @var TemporaryUploadedFile $raw */
            $raw = $this->aiRawImage;
            $binary = file_get_contents($raw->getRealPath());

            if ($binary === false || $binary === '') {
                throw new RuntimeException('Could not read the raw photo.');
            }

            $mime = $raw->getMimeType() ?: 'image/jpeg';

            $result = $gemini->generateImage([
                ['text' => trim($this->aiPrompt)],
                [
                    'inline_data' => [
                        'mime_type' => $mime,
                        'data' => base64_encode($binary),
                    ],
                ],
            ], 'You enhance product photos for a Bangladeshi jewelry e-commerce catalog. Preserve the product identity from the reference photo. Return one polished product image.');

            $this->aiCandidates[] = [
                'id' => (string) Str::uuid(),
                'mime' => $result['mime'],
                'base64' => $result['base64'],
                'name' => 'ai-generated-'.(count($this->aiCandidates) + 1).'.jpg',
            ];

            AiImagePrompt::remember(trim($this->aiPrompt), Auth::id());
        } catch (Throwable $e) {
            $this->aiGenerateError = $e->getMessage();
        } finally {
            $this->aiGenerating = false;
        }
    }

    public function updateAiCandidate(string $id, string $mime, string $base64): void
    {
        foreach ($this->aiCandidates as $index => $candidate) {
            if (($candidate['id'] ?? null) !== $id) {
                continue;
            }

            $this->aiCandidates[$index]['mime'] = $mime !== '' ? $mime : 'image/jpeg';
            $this->aiCandidates[$index]['base64'] = $base64;
            $this->aiCandidates[$index]['name'] = preg_replace('/\.\w+$/', '.jpg', (string) $candidate['name']) ?: 'ai-edited.jpg';

            return;
        }
    }

    public function removeAiCandidate(string $id): void
    {
        $this->aiCandidates = array_values(array_filter(
            $this->aiCandidates,
            fn (array $candidate) => ($candidate['id'] ?? null) !== $id,
        ));
    }

    public function promoteAiCandidate(string $id, ProductImageService $images): void
    {
        $this->aiGenerateError = null;

        if (! $this->product) {
            $this->ensureProductSaved();
        }

        $candidate = collect($this->aiCandidates)->firstWhere('id', $id);

        if (! is_array($candidate)) {
            $this->aiGenerateError = 'Generated image not found in this session.';

            return;
        }

        $binary = base64_decode((string) $candidate['base64'], true);

        if ($binary === false || $binary === '') {
            $this->aiGenerateError = 'Generated image data is invalid.';

            return;
        }

        $mime = (string) ($candidate['mime'] ?? 'image/jpeg');
        $extension = match (true) {
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            default => 'jpg',
        };

        $tempPath = tempnam(sys_get_temp_dir(), 'aiimg_');

        if ($tempPath === false) {
            $this->aiGenerateError = 'Could not create a temporary file.';

            return;
        }

        $pathWithExt = $tempPath.'.'.$extension;
        rename($tempPath, $pathWithExt);
        file_put_contents($pathWithExt, $binary);

        try {
            $upload = new UploadedFile(
                $pathWithExt,
                (string) ($candidate['name'] ?? 'ai-generated.'.$extension),
                $mime,
                null,
                true,
            );

            $images->store($this->product, $upload, $this->product->name);
            $this->removeAiCandidate($id);
            $this->refreshImages();
            $this->syncImageAlts();
            $this->message = 'AI image added to product gallery.';
        } finally {
            if (is_file($pathWithExt)) {
                @unlink($pathWithExt);
            }
        }
    }

    public function save(): void
    {
        $existingPrice = $this->product?->price;
        $existingCompareAtPrice = $this->product?->compare_at_price;
        $this->ensureProductSaved();

        if ($this->product?->priced_image_path
            && ((float) $existingPrice !== (float) $this->product->price
                || (float) $existingCompareAtPrice !== (float) $this->product->compare_at_price)) {
            app(ProductPricedImageService::class)->generate($this->product->fresh());
        }

        if ($this->justCreated) {
            $this->justCreated = false;
            $this->redirect(route('admin.products.edit', $this->product), navigate: true);

            return;
        }

        if (! str_starts_with((string) $this->message, 'Warning:')) {
            $this->message = 'Product saved.';
        }
    }

    /**
     * Create or update the product without redirecting. Call before uploading images on create.
     */
    public function ensureProductSaved(): void
    {
        $this->message = null;
        $wasCreate = $this->product === null;
        $this->persistProduct();
        $this->justCreated = $wasCreate;
    }

    public function uploadImages(ProductImageService $images): void
    {
        if (! $this->product) {
            $this->ensureProductSaved();
        }

        $this->validate([
            'newImages' => ['required', 'array', 'min:1'],
            'newImages.*' => Fileinfo::storedImageItemRules(5120),
        ]);

        $count = count($this->newImages);
        $shouldRedirect = $this->justCreated;
        $this->justCreated = false;

        foreach ($this->newImages as $index => $file) {
            $alt = trim((string) ($this->pendingAlts[$index] ?? ''));
            $images->store($this->product, $file, $alt !== '' ? $alt : null);
        }

        $this->newImages = [];
        $this->pendingAlts = [];
        $this->refreshImages();
        $this->syncImageAlts();

        if ($this->product->priced_image_path) {
            app(ProductPricedImageService::class)->generate($this->product->fresh());
        }

        if ($shouldRedirect) {
            $this->redirect(route('admin.products.edit', $this->product), navigate: true);

            return;
        }

        $this->message = $count === 1 ? 'Image uploaded.' : "{$count} images uploaded.";
    }

    /**
     * @return array<string, mixed>
     */
    private function persistProduct(): array
    {
        $slugUnique = $this->product
            ? 'unique:products,slug,'.$this->product->id
            : 'unique:products,slug';

        $validated = $this->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', $slugUnique],
            'sku' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'commission' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && (float) $value <= (float) $this->price) {
                    $fail('Regular price must be greater than selling price.');
                }
            }],
            'stock_quantity' => ['integer', 'min:0'],
            'display_order' => ['integer', 'min:0', 'max:32767'],
            'is_published' => ['boolean'],
            'is_featured' => ['boolean'],
        ]);

        if ($validated['slug'] === '') {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $validated['price'] = (int) round((float) $validated['price']);
        $validated['purchase_price'] = (int) round((float) ($validated['purchase_price'] ?? 0));
        $validated['commission'] = (int) round((float) ($validated['commission'] ?? 0));
        $validated['max_discount'] = isset($validated['max_discount']) && $validated['max_discount'] !== ''
            ? (int) round((float) $validated['max_discount'])
            : null;
        $validated['compare_at_price'] = isset($validated['compare_at_price']) && $validated['compare_at_price'] !== ''
            ? (int) round((float) $validated['compare_at_price'])
            : null;
        $validated['sku'] = $validated['sku'] !== '' ? $validated['sku'] : null;
        $validated['description'] = $validated['description'] !== '' ? $validated['description'] : null;

        $marginCap = $validated['price'] - $validated['purchase_price'];
        if ($validated['max_discount'] !== null && $validated['max_discount'] > $marginCap) {
            $this->message = 'Warning: max discount (৳'.number_format($validated['max_discount'], 0).') exceeds unit margin (৳'.number_format(max(0, $marginCap), 0).'). Saved anyway.';
        }

        if ($this->product) {
            $this->product->update($validated);
            $this->product->refresh();
        } else {
            $this->product = Product::query()->create($validated);
        }

        return $validated;
    }

    public function delete(ProductImageService $images): void
    {
        if (! $this->product) {
            return;
        }

        $images->deleteProduct($this->product);
        $this->redirect(route('admin.products'), navigate: true);
    }

    public function persistImageAlt(int $imageId): void
    {
        $alt = trim((string) ($this->imageAlts[$imageId] ?? ''));

        $this->findOwnedImage($imageId)->update([
            'alt' => $alt !== '' ? $alt : $this->product->name,
        ]);
    }

    public function deleteImage(int $imageId, ProductImageService $images): void
    {
        $image = $this->findOwnedImage($imageId);
        $images->delete($image);
        $this->refreshImages();
        $this->syncImageAlts();

        if ($this->product->priced_image_path) {
            app(ProductPricedImageService::class)->generate($this->product->fresh());
        }

        $this->message = 'Image removed.';
    }

    public function setPrimaryImage(int $imageId, ProductImageService $images): void
    {
        $image = $this->findOwnedImage($imageId);
        $images->setPrimary($image);
        $this->refreshImages();

        if ($this->product->priced_image_path) {
            app(ProductPricedImageService::class)->generate($this->product->fresh());
        }

        $this->message = 'Primary image updated.';
    }

    public function moveImageEarlier(int $imageId, ProductImageService $images): void
    {
        $images->moveEarlier($this->findOwnedImage($imageId));
        $this->refreshImages();
    }

    public function moveImageLater(int $imageId, ProductImageService $images): void
    {
        $images->moveLater($this->findOwnedImage($imageId));
        $this->refreshImages();
    }

    public function render()
    {
        $recentPrompts = AiImagePrompt::query()
            ->recent(12)
            ->get(['id', 'prompt', 'last_used_at', 'use_count']);

        return view('livewire.admin.admin-product-edit', [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'recentAiPrompts' => $recentPrompts,
            'geminiConfigured' => app(GeminiClient::class)->isConfigured(),
        ])->title($this->title());
    }

    private function findOwnedImage(int $imageId): ProductImage
    {
        return ProductImage::query()
            ->where('product_id', $this->product->id)
            ->whereKey($imageId)
            ->firstOrFail();
    }

    private function refreshImages(): void
    {
        $this->product->load(['images' => fn ($q) => $q->orderBy('sort_order')]);
    }

    private function syncImageAlts(): void
    {
        $this->imageAlts = $this->product->images
            ->mapWithKeys(fn (ProductImage $image) => [$image->id => (string) ($image->alt ?? '')])
            ->all();
    }

    private function fillPricedImageLayout(?ProductPricedImageService $pricedImages = null): void
    {
        $layout = $pricedImages?->normalizeLayout($this->product?->priced_image_layout ?? [])
            ?? app(ProductPricedImageService::class)->normalizeLayout($this->product?->priced_image_layout ?? []);

        $this->pricedImageX = (int) $layout['x'];
        $this->pricedImageY = (int) $layout['y'];
        $this->pricedImageFont = (int) $layout['font'];
    }
}
