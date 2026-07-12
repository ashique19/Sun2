<?php

namespace App\Livewire\Admin;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Admin\ProductImageService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

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

    public int $stock_quantity = 0;

    public int $display_order = 0;

    public bool $is_published = false;

    public bool $is_featured = false;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newImages = [];

    /** @var array<int, string> */
    public array $pendingAlts = [];

    /** @var array<int, string> */
    public array $imageAlts = [];

    public ?string $message = null;

    /** Set by ensureProductSaved() so uploadImages can redirect after create. */
    public bool $justCreated = false;

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
        $this->stock_quantity = (int) $product->stock_quantity;
        $this->display_order = (int) $product->display_order;
        $this->is_published = (bool) $product->is_published;
        $this->is_featured = (bool) $product->is_featured;
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

    public function save(): void
    {
        $this->ensureProductSaved();

        if ($this->justCreated) {
            $this->justCreated = false;
            $this->redirect(route('admin.products.edit', $this->product), navigate: true);

            return;
        }

        $this->message = 'Product saved.';
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
            'newImages.*' => \App\Support\Fileinfo::storedImageItemRules(5120),
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
        $validated['sku'] = $validated['sku'] !== '' ? $validated['sku'] : null;
        $validated['description'] = $validated['description'] !== '' ? $validated['description'] : null;

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
        $this->message = 'Image removed.';
    }

    public function setPrimaryImage(int $imageId, ProductImageService $images): void
    {
        $image = $this->findOwnedImage($imageId);
        $images->setPrimary($image);
        $this->refreshImages();
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
        return view('livewire.admin.admin-product-edit', [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
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
}
