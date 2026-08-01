<?php

namespace App\Livewire\Admin;

use App\Models\Product;
use App\Models\SocialPost;
use App\Models\SocialPostPublication;
use App\Services\Social\MetaGraphSocialPublisher;
use App\Services\Social\SocialPostCollageService;
use App\Support\AdminAccess;
use App\Support\StorefrontAssets;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class AdminSocialPostsCreate extends Component
{
    #[Url(as: 'products')]
    public string $products = '';

    public string $body = '';

    public string $layout = SocialPost::LAYOUT_ALBUM; // album | collage

    public bool $supportsPricedImages = false;

    public ?string $message = null;

    /** compose | publishing | done */
    public string $phase = 'compose';

    public ?int $createdPostId = null;

    /**
     * Selected image path per product id (string keys for Livewire).
     *
     * @var array<string, string>
     */
    public array $selectedImagePaths = [];

    /**
     * Per-channel progress for the active publish run.
     *
     * @var array<string, array{label: string, status: string, error: ?string, url: ?string}>
     */
    public array $channelProgress = [];

    public function mount(): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->supportsPricedImages = Schema::hasColumn('products', 'priced_image_path');
        $this->syncSelectedImageDefaults();
    }

    public function updatedProducts(): void
    {
        $this->syncSelectedImageDefaults();
    }

    /**
     * @return list<string>
     */
    public function selectedChannels(): array
    {
        return [SocialPostPublication::CHANNEL_FACEBOOK];
    }

    /**
     * @return list<int>
     */
    public function selectedProductIds(): array
    {
        $raw = array_filter(array_map('trim', explode(',', (string) $this->products)));

        return array_values(array_unique(array_filter(array_map(
            static fn (string $v) => (int) $v,
            $raw
        ), static fn (int $id) => $id > 0)));
    }

    /**
     * @param  list<int|string>  $orderedIds
     */
    public function reorderProducts(array $orderedIds): void
    {
        if ($this->phase !== 'compose') {
            return;
        }

        $current = $this->selectedProductIds();
        $normalized = [];

        foreach ($orderedIds as $id) {
            $id = (int) $id;
            if ($id > 0 && in_array($id, $current, true) && ! in_array($id, $normalized, true)) {
                $normalized[] = $id;
            }
        }

        foreach ($current as $id) {
            if (! in_array($id, $normalized, true)) {
                $normalized[] = $id;
            }
        }

        $this->products = implode(',', $normalized);
    }

    public function selectProductImage(int $productId, string $path): void
    {
        if ($this->phase !== 'compose' || $productId <= 0) {
            return;
        }

        $path = trim($path);
        if ($path === '' || ! in_array($productId, $this->selectedProductIds(), true)) {
            return;
        }

        $product = Product::query()
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')])
            ->find($productId);

        if (! $product) {
            return;
        }

        $allowed = collect($this->imageOptionsForProduct($product))->pluck('path')->all();
        if (! in_array($path, $allowed, true)) {
            return;
        }

        $this->selectedImagePaths[(string) $productId] = $path;
    }

    public function removeProduct(int $productId): void
    {
        if ($this->phase !== 'compose') {
            return;
        }

        $ids = array_values(array_filter(
            $this->selectedProductIds(),
            static fn (int $id) => $id !== $productId
        ));

        $this->products = implode(',', $ids);
        unset($this->selectedImagePaths[(string) $productId]);
    }

    /**
     * Create the on-site social post and prepare channel progress rows.
     * Actual Meta publishing happens via publishSelectedChannel() for live progress.
     */
    public function createPost(SocialPostCollageService $collage): void
    {
        $this->message = null;

        if ($this->phase !== 'compose') {
            return;
        }

        $ids = $this->selectedProductIds();
        if ($ids === []) {
            throw ValidationException::withMessages([
                'products' => 'Select at least one product.',
            ]);
        }

        $selectedProducts = Product::query()
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($selectedProducts->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'products' => 'Some selected products no longer exist.',
            ]);
        }

        $this->validate([
            'body' => ['required', 'string', 'min:3', 'max:5000'],
            'layout' => ['required', 'in:'.SocialPost::LAYOUT_ALBUM.','.SocialPost::LAYOUT_COLLAGE],
        ]);

        $resolvedPaths = [];
        $missing = [];

        foreach ($ids as $pid) {
            $product = $selectedProducts->get($pid);
            $chosen = trim((string) ($this->selectedImagePaths[(string) $pid] ?? ''));
            $allowed = collect($this->imageOptionsForProduct($product))->pluck('path')->all();

            if ($chosen === '' || ! in_array($chosen, $allowed, true)) {
                $chosen = $allowed[0] ?? '';
            }

            if ($chosen === '') {
                $missing[] = $product?->name ?? (string) $pid;

                continue;
            }

            $resolvedPaths[$pid] = $chosen;
            $this->selectedImagePaths[(string) $pid] = $chosen;
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'products' => 'Missing images for: '.implode(', ', $missing),
            ]);
        }

        $allPriced = true;
        foreach ($ids as $pid) {
            $product = $selectedProducts->get($pid);
            $priced = $this->supportsPricedImages ? trim((string) ($product->priced_image_path ?? '')) : '';
            if ($priced === '' || $resolvedPaths[$pid] !== $priced) {
                $allPriced = false;
                break;
            }
        }

        $post = SocialPost::query()->create([
            'body' => $this->body,
            'image_source' => $allPriced ? SocialPost::IMAGE_SOURCE_PRICED : SocialPost::IMAGE_SOURCE_THUMB,
            'layout' => $this->layout,
            'status' => SocialPost::STATUS_PUBLISHED,
            'show_on_homepage' => true,
            'created_by' => auth()->id(),
        ]);

        foreach ($ids as $sortOrder => $productId) {
            $product = $selectedProducts->get($productId);
            $selectedPath = $resolvedPaths[$productId];
            $pricedPath = $this->supportsPricedImages ? ($product->priced_image_path ?? null) : null;
            $isPriced = is_string($pricedPath) && trim($pricedPath) !== '' && $selectedPath === trim($pricedPath);

            $post->products()->attach((int) $productId, [
                'sort_order' => (int) $sortOrder,
                'thumb_snapshot_path' => $isPriced ? null : $selectedPath,
                'priced_snapshot_path' => $isPriced ? $selectedPath : null,
                'selected_image_path' => $selectedPath,
            ]);
        }

        if ($this->layout === SocialPost::LAYOUT_COLLAGE) {
            $imagePathsOrUrls = array_values($resolvedPaths);
            $collageRelative = 'img/social-posts/collage_'.$post->id.'.jpg';

            try {
                $collage->compose($imagePathsOrUrls, $collageRelative);
                $post->update([
                    'collage_path' => $collageRelative,
                    'thumbnail_path' => $collageRelative,
                ]);
            } catch (\Throwable) {
                $first = $resolvedPaths[(int) $ids[0]] ?? null;
                $post->update([
                    'thumbnail_path' => is_string($first) && trim($first) !== '' ? $first : null,
                ]);
            }
        } else {
            $first = $resolvedPaths[(int) $ids[0]] ?? null;
            $post->update([
                'thumbnail_path' => is_string($first) && trim($first) !== '' ? $first : null,
            ]);
        }

        $this->createdPostId = $post->id;
        $this->channelProgress = [];

        foreach ($this->selectedChannels() as $channel) {
            $this->channelProgress[$channel] = [
                'label' => $this->channelLabel($channel),
                'status' => 'waiting',
                'error' => null,
                'url' => null,
            ];
        }

        $this->phase = 'publishing';
    }

    public function markChannelPosting(string $channel): void
    {
        if ($this->phase !== 'publishing' || ! isset($this->channelProgress[$channel])) {
            return;
        }

        if (! in_array($this->channelProgress[$channel]['status'], ['waiting', 'posting'], true)) {
            return;
        }

        $this->channelProgress[$channel]['status'] = 'posting';
        $this->channelProgress[$channel]['error'] = null;
    }

    public function publishSelectedChannel(string $channel, MetaGraphSocialPublisher $publisher): void
    {
        if ($this->phase !== 'publishing' || ! $this->createdPostId || ! isset($this->channelProgress[$channel])) {
            return;
        }

        if (! in_array($this->channelProgress[$channel]['status'], ['waiting', 'posting'], true)) {
            return;
        }

        $this->channelProgress[$channel]['status'] = 'posting';

        $post = SocialPost::query()
            ->with(['products'])
            ->find($this->createdPostId);

        if (! $post) {
            $this->channelProgress[$channel] = [
                'label' => $this->channelLabel($channel),
                'status' => 'failed',
                'error' => 'Social post was not found.',
                'url' => null,
            ];
            $this->finishIfComplete();

            return;
        }

        $publication = $publisher->publishChannel($post, $channel);

        $this->channelProgress[$channel] = [
            'label' => $this->channelLabel($channel),
            'status' => $publication->status === SocialPostPublication::STATUS_SUCCESS ? 'success' : 'failed',
            'error' => $publication->error,
            'url' => $publication->external_url,
        ];

        $this->finishIfComplete();
    }

    /**
     * Backward-compatible entry used by older tests/callers: create + publish all selected channels.
     */
    public function publish(MetaGraphSocialPublisher $publisher, SocialPostCollageService $collage): void
    {
        $this->createPost($collage);

        if ($this->phase !== 'publishing') {
            return;
        }

        foreach (array_keys($this->channelProgress) as $channel) {
            $this->publishSelectedChannel($channel, $publisher);
        }
    }

    public function render()
    {
        $ids = $this->selectedProductIds();

        $products = $ids === []
            ? collect()
            : Product::query()
                ->with(['images' => fn ($q) => $q->orderBy('sort_order')])
                ->whereIn('id', $ids)
                ->get();

        $products = $products->sortBy(fn (Product $p) => array_search($p->id, $ids, true))->values();

        $previewImages = [];
        foreach ($products as $product) {
            $path = $this->selectedImagePaths[(string) $product->id] ?? null;
            if (! is_string($path) || trim($path) === '') {
                $options = $this->imageOptionsForProduct($product);
                $path = $options[0]['path'] ?? null;
            }

            $url = StorefrontAssets::url($path);
            if ($url) {
                $previewImages[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'path' => $path,
                    'url' => $url,
                    'store_url' => route('product.show', $product),
                ];
            }
        }

        $productRows = $products->map(function (Product $product) {
            $options = $this->imageOptionsForProduct($product);
            $selected = $this->selectedImagePaths[(string) $product->id] ?? ($options[0]['path'] ?? null);

            return [
                'product' => $product,
                'options' => $options,
                'selected' => $selected,
                'selected_url' => StorefrontAssets::url($selected),
                'store_url' => route('product.show', $product),
            ];
        });

        return view('livewire.admin.admin-social-post-create', [
            'selectedProducts' => $products,
            'productRows' => $productRows,
            'previewImages' => $previewImages,
            'facebookPageName' => (string) config('app.name'),
        ]);
    }

    private function finishIfComplete(): void
    {
        foreach ($this->channelProgress as $row) {
            if (! in_array($row['status'], ['success', 'failed'], true)) {
                return;
            }
        }

        $this->phase = 'done';
        $this->message = 'Publishing finished.';
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            SocialPostPublication::CHANNEL_FACEBOOK => 'Facebook',
            SocialPostPublication::CHANNEL_INSTAGRAM => 'Instagram',
            default => ucfirst($channel),
        };
    }

    private function syncSelectedImageDefaults(): void
    {
        $ids = $this->selectedProductIds();
        $keep = [];

        if ($ids === []) {
            $this->selectedImagePaths = [];

            return;
        }

        $products = Product::query()
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $key = (string) $id;
            $product = $products->get($id);
            $options = $product ? $this->imageOptionsForProduct($product) : [];
            $allowed = collect($options)->pluck('path')->all();
            $current = trim((string) ($this->selectedImagePaths[$key] ?? ''));

            if ($current !== '' && in_array($current, $allowed, true)) {
                $keep[$key] = $current;
            } elseif ($allowed !== []) {
                $keep[$key] = $allowed[0];
            }
        }

        $this->selectedImagePaths = $keep;
    }

    /**
     * @return list<array{path: string, url: ?string, label: string, kind: string}>
     */
    private function imageOptionsForProduct(Product $product): array
    {
        $options = [];
        $seen = [];

        foreach ($product->images as $image) {
            $path = is_string($image->path) ? trim($image->path) : '';
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $options[] = [
                'path' => $path,
                'url' => StorefrontAssets::url($path),
                'label' => $image->is_primary ? 'Primary' : 'Gallery',
                'kind' => 'gallery',
            ];
        }

        if ($this->supportsPricedImages) {
            $priced = is_string($product->priced_image_path ?? null) ? trim((string) $product->priced_image_path) : '';
            if ($priced !== '' && ! isset($seen[$priced])) {
                $options[] = [
                    'path' => $priced,
                    'url' => StorefrontAssets::url($priced),
                    'label' => 'Priced',
                    'kind' => 'priced',
                ];
            }
        }

        return $options;
    }
}
