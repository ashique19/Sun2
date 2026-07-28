<?php

namespace App\Livewire\Admin;

use App\Models\Product;
use App\Models\SocialPost;
use App\Models\SocialPostPublication;
use App\Services\Social\MetaGraphSocialPublisher;
use App\Services\Social\SocialPostCollageService;
use App\Support\AdminAccess;
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

    public string $imageSource = SocialPost::IMAGE_SOURCE_THUMB; // thumb | priced

    public string $layout = SocialPost::LAYOUT_ALBUM; // album | collage

    public bool $postToFacebook = true;

    public bool $postToInstagram = true;

    public bool $supportsPricedImages = false;

    public ?string $message = null;

    /** compose | publishing | done */
    public string $phase = 'compose';

    public ?int $createdPostId = null;

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

        // If DB doesn’t have priced-image support yet, never allow the UI to switch to it.
        if (! $this->supportsPricedImages && $this->imageSource === SocialPost::IMAGE_SOURCE_PRICED) {
            $this->imageSource = SocialPost::IMAGE_SOURCE_THUMB;
        }
    }

    /**
     * @return list<string>
     */
    public function selectedChannels(): array
    {
        $channels = [];

        if ($this->postToFacebook) {
            $channels[] = SocialPostPublication::CHANNEL_FACEBOOK;
        }

        if ($this->postToInstagram) {
            $channels[] = SocialPostPublication::CHANNEL_INSTAGRAM;
        }

        return $channels;
    }

    /**
     * @return array<int>
     */
    private function selectedProductIds(): array
    {
        $raw = array_filter(array_map('trim', explode(',', (string) $this->products)));

        return array_values(array_unique(array_filter(array_map(
            static fn (string $v) => (int) $v,
            $raw
        ), static fn (int $id) => $id > 0)));
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

        if ($this->selectedChannels() === []) {
            throw ValidationException::withMessages([
                'postToFacebook' => 'Select at least one social network to post to.',
            ]);
        }

        $supportsPriced = $this->supportsPricedImages;
        if ($this->imageSource === SocialPost::IMAGE_SOURCE_PRICED && ! $supportsPriced) {
            throw ValidationException::withMessages([
                'imageSource' => '“Images with price” is not configured yet (priced image fields missing).',
            ]);
        }

        $selectedProducts = Product::query()
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
            'imageSource' => ['required', 'in:'.SocialPost::IMAGE_SOURCE_THUMB.','.SocialPost::IMAGE_SOURCE_PRICED],
            'layout' => ['required', 'in:'.SocialPost::LAYOUT_ALBUM.','.SocialPost::LAYOUT_COLLAGE],
        ]);

        // When priced images are requested, ensure each product has a snapshot path.
        if ($this->imageSource === SocialPost::IMAGE_SOURCE_PRICED) {
            $missing = [];
            foreach ($ids as $pid) {
                $product = $selectedProducts->get($pid);
                if (! $product) {
                    $missing[] = (string) $pid;

                    continue;
                }

                $path = $product->priced_image_path ?? null;
                if (! is_string($path) || trim($path) === '') {
                    $missing[] = $product->name;
                }
            }

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'imageSource' => 'Missing priced images for: '.implode(', ', $missing),
                ]);
            }
        }

        $post = SocialPost::query()->create([
            'body' => $this->body,
            'image_source' => $this->imageSource,
            'layout' => $this->layout,
            'status' => SocialPost::STATUS_PUBLISHED, // homepage should show it after publish click
            'created_by' => auth()->id(),
        ]);

        $thumbSnapshotPaths = [];
        $pricedSnapshotPaths = [];

        foreach ($ids as $productId) {
            $product = $selectedProducts->get($productId);
            if (! $product) {
                continue;
            }

            $thumbSnapshotPaths[$productId] = $product->primaryImagePath();
            $pricedSnapshotPaths[$productId] = $product->priced_image_path ?? null;
        }

        foreach ($ids as $sortOrder => $productId) {
            $pivotData = [
                'sort_order' => (int) $sortOrder,
                'thumb_snapshot_path' => $this->imageSource === SocialPost::IMAGE_SOURCE_THUMB
                    ? ($thumbSnapshotPaths[$productId] ?? null)
                    : null,
                'priced_snapshot_path' => $this->imageSource === SocialPost::IMAGE_SOURCE_PRICED
                    ? ($pricedSnapshotPaths[$productId] ?? null)
                    : null,
            ];

            $post->products()->attach((int) $productId, $pivotData);
        }

        if ($this->layout === SocialPost::LAYOUT_COLLAGE) {
            $imagePathsOrUrls = [];
            foreach ($ids as $productId) {
                $pivotThumb = $thumbSnapshotPaths[$productId] ?? null;
                $pivotPriced = $pricedSnapshotPaths[$productId] ?? null;
                $chosen = $this->imageSource === SocialPost::IMAGE_SOURCE_PRICED ? $pivotPriced : $pivotThumb;
                if (is_string($chosen) && trim($chosen) !== '') {
                    $imagePathsOrUrls[] = $chosen;
                }
            }

            // For v1: compose if possible; if it fails we still keep the post.
            $collageRelative = 'img/social-posts/collage_'.$post->id.'.jpg';

            try {
                $collage->compose($imagePathsOrUrls, $collageRelative);
                $post->update([
                    'collage_path' => $collageRelative,
                    'thumbnail_path' => $collageRelative,
                ]);
            } catch (\Throwable) {
                // Fall back to the first product image.
                $first = (int) $ids[0];
                $fallback = $this->imageSource === SocialPost::IMAGE_SOURCE_PRICED
                    ? ($pricedSnapshotPaths[$first] ?? null)
                    : ($thumbSnapshotPaths[$first] ?? null);

                $post->update([
                    'thumbnail_path' => is_string($fallback) && trim($fallback) !== '' ? $fallback : null,
                ]);
            }
        } else {
            // Album: homepage thumbnail is the first image.
            $first = (int) $ids[0];
            $firstPath = $this->imageSource === SocialPost::IMAGE_SOURCE_PRICED
                ? ($pricedSnapshotPaths[$first] ?? null)
                : ($thumbSnapshotPaths[$first] ?? null);

            $post->update([
                'thumbnail_path' => is_string($firstPath) && trim($firstPath) !== '' ? $firstPath : null,
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

        // Skip channels already finished (progress UI may remount and retry).
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

        // Preserve selection order from query param.
        $products = $products->sortBy(fn (Product $p) => array_search($p->id, $ids, true));

        return view('livewire.admin.admin-social-post-create', [
            'selectedProducts' => $products,
        ]);
    }
}
