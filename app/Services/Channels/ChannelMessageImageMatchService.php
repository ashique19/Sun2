<?php

namespace App\Services\Channels;

use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageMatchMemory;
use App\Models\User;
use App\Services\Admin\ProductImageMatcher;
use App\Services\Admin\ProductImageMatchIndex;
use App\Services\Admin\ProductImageSignatureService;
use App\Support\StorefrontAssets;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbound photo → catalog product, persisted per message.
 *
 * Order: staff memory (exact / near hash) → visual region matcher. The ranked
 * candidates are stored on the message (image_match_meta) so the inbox can offer
 * one-click suggestions and the tag modal opens without re-matching. Staff
 * "Not this" rejections are remembered per photo; staff tags are learned as
 * visual signatures so the next similar screenshot matches automatically.
 */
class ChannelMessageImageMatchService
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_SUGGESTED = 'suggested';

    public const STATUS_NONE = 'none';

    public const STATUS_TAGGED = 'tagged';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /** Bump to re-match stored "none"/"suggested" results after matcher changes. */
    public const META_VERSION = 1;

    public const STORED_CANDIDATES = 5;

    private static int $prewarmScheduled = 0;

    public function __construct(
        private ChannelInboundMediaService $media,
        private ProductImageMatchMemoryService $matchMemory,
        private ProductImageMatcher $matcher,
        private ProductImageMatchIndex $index,
        private ProductImageSignatureService $signatures,
    ) {}

    public function isEligible(ChannelMessage $message): bool
    {
        return $message->direction === ChannelMessage::DIRECTION_INBOUND
            && $message->isImageAttachment();
    }

    /**
     * Whether the photo still needs matching. Pass the current visual index stamp to
     * also retry "none"/"suggested" results after the catalog or learned memory changed.
     */
    public function needsMatching(ChannelMessage $message, ?string $stamp = null): bool
    {
        if (! $this->isEligible($message) || $message->matched_product_id) {
            return false;
        }

        if ($message->image_match_checked_at === null) {
            return true;
        }

        $meta = $this->meta($message);
        if ($meta === []) {
            // Checked (or cleared by staff) before candidates were persisted.
            return false;
        }

        if ((int) ($meta['v'] ?? 0) !== self::META_VERSION) {
            return true;
        }

        return in_array($meta['status'] ?? null, [self::STATUS_NONE, self::STATUS_SUGGESTED], true)
            && $stamp !== null
            && ($meta['stamp'] ?? null) !== $stamp;
    }

    public function indexStamp(): string
    {
        return $this->index->stamp();
    }

    /**
     * Match the photo if needed and persist the result. Safe to call concurrently.
     */
    public function resolve(ChannelMessage $message, bool $force = false): ChannelMessage
    {
        if (! $this->isEligible($message)) {
            return $message;
        }

        if (! $force && ! $this->needsMatching($message, $this->index->stamp())) {
            return $message;
        }

        $lock = Cache::lock('channel-image-match:'.$message->id, 60);
        if (! $lock->get()) {
            return $message->refresh();
        }

        try {
            $message->refresh();
            if ($force || $this->needsMatching($message, $this->index->stamp())) {
                $this->runMatch($message);
            }
        } finally {
            $lock->release();
        }

        return $message;
    }

    /**
     * Auto-attached or staff-tagged published product for the photo (matching first if needed).
     *
     * @return array{product_id: int, name: string, match_percent: float, strategy: string}|null
     */
    public function bestAutoMatch(ChannelMessage $message): ?array
    {
        if (! $this->isEligible($message)) {
            return null;
        }

        $message = $this->resolve($message);
        $productId = (int) ($message->matched_product_id ?? 0);
        if ($productId <= 0) {
            return null;
        }

        $product = Product::query()->published()->whereKey($productId)->first(['id', 'name']);
        if ($product === null) {
            return null;
        }

        return [
            'product_id' => $productId,
            'name' => (string) $product->name,
            'match_percent' => $this->matchPercent($message),
            'strategy' => $this->strategy($message),
        ];
    }

    /**
     * How the attached product was chosen: visual | staff_memory | manual | stored.
     */
    public function strategy(ChannelMessage $message): string
    {
        $meta = $this->meta($message);

        return match ($meta['status'] ?? null) {
            null => 'stored',
            self::STATUS_TAGGED => 'manual',
            default => (string) ($meta['source'] ?? 'visual'),
        };
    }

    /**
     * Score of the attached product (100 when tagged by staff or not scored).
     */
    public function matchPercent(ChannelMessage $message): float
    {
        $productId = (int) ($message->matched_product_id ?? 0);
        foreach ($this->meta($message)['candidates'] ?? [] as $candidate) {
            if ((int) $candidate['product_id'] === $productId) {
                return round(((float) $candidate['score']) * 100, 1);
            }
        }

        return 100.0;
    }

    /**
     * Stored candidates (excluding rejected) as product rows.
     *
     * @return list<array{product_id:int, name:string, sku:?string, price:float, stock_quantity:int, image_url:?string, match_percent:float}>
     */
    public function candidateRows(ChannelMessage $message, int $limit = 3, bool $includeAttached = false): array
    {
        return $this->suggestionRows([$message], $limit, $includeAttached)[(int) $message->id] ?? [];
    }

    /**
     * Batched candidateRows for a thread (one product query).
     *
     * @param  iterable<ChannelMessage>  $messages
     * @return array<int, list<array{product_id:int, name:string, sku:?string, price:float, stock_quantity:int, image_url:?string, match_percent:float}>>
     */
    public function suggestionRows(iterable $messages, int $limit = 3, bool $includeAttached = false): array
    {
        $perMessage = [];
        foreach ($messages as $message) {
            $meta = $this->meta($message);
            $rejected = array_map('intval', $meta['rejected'] ?? []);
            $matchedProductId = (int) ($message->matched_product_id ?? 0);
            $suggestScore = ProductImageMatcher::suggestScore();

            $perMessage[(int) $message->id] = array_values(array_filter(
                $meta['candidates'] ?? [],
                function (array $candidate) use ($rejected, $matchedProductId, $includeAttached, $suggestScore): bool {
                    $productId = (int) $candidate['product_id'];
                    if (in_array($productId, $rejected, true)) {
                        return false;
                    }
                    if ($productId === $matchedProductId) {
                        return $includeAttached;
                    }

                    return (float) $candidate['score'] >= $suggestScore;
                },
            ));
        }

        $lookup = $this->productLookup(array_merge([], ...array_values($perMessage)));

        $rows = [];
        foreach ($perMessage as $messageId => $candidates) {
            $rows[$messageId] = $this->rowsFor($candidates, $lookup, $limit);
        }

        return $rows;
    }

    /**
     * Visual search for an arbitrary image or a region of it (staff crop, pasted order image).
     *
     * @param  array{left: float, top: float, width: float, height: float}|null  $regionFractions
     * @param  list<int>  $excludeProductIds
     * @return array{
     *     rows: list<array{product_id:int, name:string, sku:?string, price:float, stock_quantity:int, image_url:?string, match_percent:float}>,
     *     confident: bool
     * }
     */
    public function searchBinary(string $bytes, ?array $regionFractions = null, int $limit = 5, array $excludeProductIds = []): array
    {
        $result = $this->matcher->match($bytes, $regionFractions, $limit + count($excludeProductIds) + 3);
        $candidates = array_slice($this->publishedCandidates($result['candidates'], $excludeProductIds), 0, $limit);
        $confident = ProductImageMatcher::isConfident($candidates);
        $suggestible = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => $candidate['score'] >= ProductImageMatcher::suggestScore(),
        ));

        return [
            'rows' => $this->rowsFor($suggestible, $this->productLookup($suggestible), $limit),
            'confident' => $confident,
        ];
    }

    /**
     * Staff confirmed the product on this photo: attach it and learn from it.
     *
     * @param  array{left: float, top: float, width: float, height: float}|null  $regionFractions  staff crop, when known
     */
    public function tag(ChannelMessage $message, Product $product, ?User $user = null, ?array $regionFractions = null): void
    {
        $meta = $this->meta($message);
        $meta['v'] ??= self::META_VERSION;
        $meta['status'] = self::STATUS_TAGGED;
        $meta['source'] = 'staff';
        $meta['rejected'] = array_values(array_diff(array_map('intval', $meta['rejected'] ?? []), [(int) $product->id]));

        $message->forceFill([
            'matched_product_id' => $product->id,
            'image_match_checked_at' => now(),
            'image_match_meta' => $meta,
        ])->save();

        if (! $this->isEligible($message)) {
            return;
        }

        $this->matchMemory->rememberFromStaffTag($message, $product, $user);

        try {
            $this->learnSignature($message, $product, $user, $regionFractions ?? $this->regionFor($meta, (int) $product->id));
        } catch (Throwable $e) {
            Log::warning('Inbox image tag signature learning failed.', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * "Not this" / Clear: detach the product, never auto-attach it to this photo again,
     * and drop memories that pointed this photo at it.
     */
    public function reject(ChannelMessage $message): ?int
    {
        $productId = (int) ($message->matched_product_id ?? 0);

        if ($this->isEligible($message)) {
            $this->matchMemory->forgetFromMessage($message);
            $message->refresh();
            if ($productId > 0) {
                $this->forgetMemoriesFor($message, $productId);
            }
        }

        $meta = $this->meta($message);
        $rejected = array_map('intval', $meta['rejected'] ?? []);
        if ($productId > 0) {
            $rejected[] = $productId;
        }
        $rejected = array_values(array_unique($rejected));

        $remaining = array_values(array_filter(
            $meta['candidates'] ?? [],
            fn (array $candidate): bool => ! in_array((int) $candidate['product_id'], $rejected, true),
        ));
        $top = (float) ($remaining[0]['score'] ?? 0.0);

        $message->forceFill([
            'matched_product_id' => null,
            'image_match_checked_at' => now(),
            'image_match_meta' => [
                ...$meta,
                'v' => $meta['v'] ?? self::META_VERSION,
                'status' => $top >= ProductImageMatcher::suggestScore() ? self::STATUS_SUGGESTED : self::STATUS_NONE,
                'source' => 'staff_rejected',
                'rejected' => $rejected,
            ],
        ])->save();

        return $productId > 0 ? $productId : null;
    }

    /**
     * Match a new inbound photo right after the webhook response (bounded per request),
     * so the result is ready before staff open the thread. HTTP only: in a long-running
     * console process after-response jobs would wait until the process exits.
     */
    public static function prewarmAfterResponse(ChannelMessage $message): void
    {
        if (! config('channels.image_match.prewarm_on_receive', true)
            || $message->direction !== ChannelMessage::DIRECTION_INBOUND
            || ! $message->isImageAttachment()
            || self::$prewarmScheduled >= max(0, (int) config('channels.image_match.prewarm_max_per_request', 6))
            || (app()->runningInConsole() && ! app()->runningUnitTests())) {
            return;
        }

        if (self::$prewarmScheduled === 0) {
            app()->terminating(static function (): void {
                self::$prewarmScheduled = 0;
            });
        }
        self::$prewarmScheduled++;
        $messageId = (int) $message->id;

        dispatch(function () use ($messageId): void {
            $message = ChannelMessage::query()->find($messageId);
            if ($message === null) {
                return;
            }

            try {
                app(self::class)->resolve($message);
            } catch (Throwable $e) {
                Log::warning('Inbound image prewarm match failed.', [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    /**
     * @return array{bytes: string, mime: string}|null
     */
    public function downloadInboundImageBytes(ChannelMessage $message): ?array
    {
        $cached = $this->media->ensureCached($message);
        if ($cached === null) {
            return null;
        }

        return [
            'bytes' => $cached['bytes'],
            'mime' => $cached['mime'],
        ];
    }

    private function runMatch(ChannelMessage $message): void
    {
        $started = microtime(true);
        $this->index->backfillMissingSignatures();
        $stamp = $this->index->stamp();
        $rejected = array_map('intval', $this->meta($message)['rejected'] ?? []);

        $persist = function (string $status, array $candidates = [], string $source = 'visual', ?int $attach = null) use ($message, $started, $stamp, $rejected): void {
            $message->forceFill([
                'matched_product_id' => $attach,
                // A failed download is retried the next time the thread opens.
                'image_match_checked_at' => $status === self::STATUS_FAILED ? null : now(),
                'image_match_meta' => [
                    'v' => self::META_VERSION,
                    'status' => $status,
                    'stamp' => $stamp,
                    'source' => $source,
                    'candidates' => $candidates,
                    'rejected' => $rejected,
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                ],
            ])->save();
        };

        try {
            $cached = $this->media->ensureCached($message);
        } catch (Throwable $e) {
            Log::debug('Inbox inbound image cache failed.', ['message_id' => $message->id, 'error' => $e->getMessage()]);
            $cached = null;
        }

        if ($cached === null) {
            $persist(self::STATUS_FAILED);

            return;
        }

        $memory = $this->matchMemory->lookup($cached['dhash'] ?? null, $cached['dct_hash'] ?? null);
        if ($memory !== null
            && ! in_array((int) $memory['product_id'], $rejected, true)
            && Product::query()->published()->whereKey($memory['product_id'])->exists()) {
            $persist(self::STATUS_MATCHED, [[
                'product_id' => (int) $memory['product_id'],
                'score' => round(((float) ($memory['match_percent'] ?? 100.0)) / 100, 4),
                'source' => 'memory',
                'source_id' => 0,
                'region' => null,
            ]], 'staff_memory', (int) $memory['product_id']);

            return;
        }

        $minBytes = max(1, (int) config('channels.ai_draft.image_min_bytes', 5000));
        if (strlen($cached['bytes']) < $minBytes) {
            $persist(self::STATUS_SKIPPED);

            return;
        }

        try {
            $ranked = $this->rankedCandidates($cached['bytes'], $stamp);
        } catch (Throwable $e) {
            Log::debug('Inbox inbound image match failed.', ['message_id' => $message->id, 'error' => $e->getMessage()]);
            $persist(self::STATUS_NONE);

            return;
        }

        $candidates = array_slice($this->publishedCandidates($ranked, $rejected), 0, self::STORED_CANDIDATES);
        $top = (float) ($candidates[0]['score'] ?? 0.0);

        if (ProductImageMatcher::isConfident($candidates)) {
            $persist(self::STATUS_MATCHED, $candidates, 'visual', $candidates[0]['product_id']);
        } elseif ($top >= ProductImageMatcher::suggestScore()) {
            $persist(self::STATUS_SUGGESTED, $candidates);
        } else {
            $persist(self::STATUS_NONE, $candidates);
        }
    }

    /**
     * Matcher output for these exact bytes, shared across messages (forwarded / re-sent photos).
     *
     * @return list<array{product_id:int, score:float, source:string, source_id:int, region: ?array}>
     */
    private function rankedCandidates(string $bytes, string $stamp): array
    {
        $cacheKey = 'inbox-image-visual-match:v1:'.sha1($bytes.'|'.$stamp);
        $stored = Cache::get($cacheKey);
        if (is_array($stored)) {
            return $stored;
        }

        $candidates = $this->matcher->match($bytes, null, self::STORED_CANDIDATES + 8)['candidates'];
        Cache::put($cacheKey, $candidates, now()->addDays(14));

        return $candidates;
    }

    /**
     * @param  list<array{product_id:int, score:float, source:string, source_id:int, region?: ?array}>  $candidates
     * @param  list<int>  $excludeProductIds
     * @return list<array{product_id:int, score:float, source:string, source_id:int, region: ?array}>
     */
    private function publishedCandidates(array $candidates, array $excludeProductIds): array
    {
        $ids = array_map(fn (array $candidate): int => (int) $candidate['product_id'], $candidates);
        if ($ids === []) {
            return [];
        }

        $published = Product::query()->published()->whereIn('id', $ids)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $kept = [];
        foreach ($candidates as $candidate) {
            $productId = (int) $candidate['product_id'];
            if (! in_array($productId, $published, true) || in_array($productId, $excludeProductIds, true)) {
                continue;
            }

            $kept[] = [
                'product_id' => $productId,
                'score' => (float) $candidate['score'],
                'source' => (string) $candidate['source'],
                'source_id' => (int) $candidate['source_id'],
                'region' => $candidate['region'] ?? null,
            ];
        }

        return $kept;
    }

    /**
     * @param  list<array{product_id:int, source?:string, source_id?:int}>  $candidates
     * @return array{products: array<int, Product>, images: array<int, string>}
     */
    private function productLookup(array $candidates): array
    {
        $productIds = array_values(array_unique(array_map(fn (array $candidate): int => (int) $candidate['product_id'], $candidates)));
        if ($productIds === []) {
            return ['products' => [], 'images' => []];
        }

        $products = Product::query()
            ->published()
            ->with(['images' => fn ($images) => $images->orderByDesc('is_primary')->orderBy('sort_order')])
            ->whereIn('id', $productIds)
            ->get(['id', 'name', 'sku', 'price', 'stock_quantity'])
            ->keyBy('id')
            ->all();

        $catalogImageIds = [];
        foreach ($candidates as $candidate) {
            if (($candidate['source'] ?? null) === 'catalog' && (int) ($candidate['source_id'] ?? 0) > 0) {
                $catalogImageIds[] = (int) $candidate['source_id'];
            }
        }

        $images = $catalogImageIds === []
            ? []
            : ProductImage::query()->whereIn('id', array_unique($catalogImageIds))->pluck('path', 'id')->all();

        return ['products' => $products, 'images' => $images];
    }

    /**
     * Rows show the catalog photo that actually matched, so staff can verify at a glance.
     *
     * @param  list<array{product_id:int, score?:float, source?:string, source_id?:int}>  $candidates
     * @param  array{products: array<int, Product>, images: array<int, string>}  $lookup
     * @return list<array{product_id:int, name:string, sku:?string, price:float, stock_quantity:int, image_url:?string, match_percent:float}>
     */
    private function rowsFor(array $candidates, array $lookup, int $limit): array
    {
        $rows = [];
        foreach ($candidates as $candidate) {
            $product = $lookup['products'][(int) $candidate['product_id']] ?? null;
            if ($product === null) {
                continue;
            }

            $matchedImage = ($candidate['source'] ?? null) === 'catalog'
                ? ($lookup['images'][(int) ($candidate['source_id'] ?? 0)] ?? null)
                : null;

            $rows[] = [
                'product_id' => (int) $product->id,
                'name' => (string) $product->name,
                'sku' => $product->sku,
                'price' => (float) $product->price,
                'stock_quantity' => (int) $product->stock_quantity,
                'image_url' => StorefrontAssets::url($matchedImage ?? $product->primaryImagePath()),
                'match_percent' => round(((float) ($candidate['score'] ?? 1.0)) * 100, 1),
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(ChannelMessage $message): array
    {
        return is_array($message->image_match_meta) ? $message->image_match_meta : [];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{left: float, top: float, width: float, height: float}|null
     */
    private function regionFor(array $meta, int $productId): ?array
    {
        foreach ($meta['candidates'] ?? [] as $candidate) {
            if ((int) $candidate['product_id'] === $productId && is_array($candidate['region'] ?? null)) {
                return $candidate['region'];
            }
        }

        return null;
    }

    /**
     * Store the confirmed photo region as a signature memory so similar screenshots match.
     *
     * @param  array{left: float, top: float, width: float, height: float}|null  $regionFractions
     */
    private function learnSignature(ChannelMessage $message, Product $product, ?User $user, ?array $regionFractions): void
    {
        $cached = $this->media->ensureCached($message);
        $minBytes = max(1, (int) config('channels.ai_draft.image_min_bytes', 5000));
        if ($cached === null || strlen($cached['bytes']) < $minBytes) {
            return;
        }

        $packed = $this->signatures->pack($this->matcher->learnedSignatures($cached['bytes'], $regionFractions));

        ProductImageMatchMemory::query()
            ->where('hash_kind', ProductImageMatchMemory::KIND_SIGNATURE)
            ->where('source_channel_message_id', $message->id)
            ->get()
            ->each->delete();

        ProductImageMatchMemory::query()->updateOrCreate(
            [
                'hash' => substr(sha1($packed), 0, 16),
                'hash_kind' => ProductImageMatchMemory::KIND_SIGNATURE,
            ],
            [
                'signature' => $packed,
                'product_id' => $product->id,
                'source_channel_message_id' => $message->id,
                'created_by' => $user?->id,
            ],
        );
    }

    /**
     * Drop exact-hash and learned-signature memories that map this photo to a rejected product.
     */
    private function forgetMemoriesFor(ChannelMessage $message, int $productId): void
    {
        $hashes = array_values(array_filter([$message->media_dhash, $message->media_dct_hash], 'filled'));

        ProductImageMatchMemory::query()
            ->where('product_id', $productId)
            ->where(function ($query) use ($message, $hashes): void {
                $query->where(function ($own) use ($message): void {
                    $own->where('hash_kind', ProductImageMatchMemory::KIND_SIGNATURE)
                        ->where('source_channel_message_id', $message->id);
                });
                if ($hashes !== []) {
                    $query->orWhere(function ($exact) use ($hashes): void {
                        $exact->whereIn('hash_kind', ['dhash', 'dct'])->whereIn('hash', $hashes);
                    });
                }
            })
            ->get()
            ->each->delete();
    }
}
