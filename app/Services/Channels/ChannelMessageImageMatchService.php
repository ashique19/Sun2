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
use Illuminate\Contracts\Cache\LockTimeoutException;
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

    /** Automatic re-tries of a failed photo (on later thread opens); staff can always retry. */
    public const MAX_AUTO_ATTEMPTS = 3;

    public const RETRY_AFTER_SECONDS = 600;

    /** Inbox render states (see renderState()). */
    public const STATE_PENDING = 'pending';

    public const STATE_STALE = 'stale';

    public const STATE_STALLED = 'stalled';

    public const STATE_DONE = 'done';

    /**
     * Admin-facing text for failure reasons stored in image_match_meta.error.
     *
     * @var array<string, string>
     */
    public const FAILURE_MESSAGES = [
        'download_failed' => 'Couldn’t download this photo (the Facebook link may have expired).',
        'timeout' => 'The server hit its PHP time limit while matching. Build the photo index under Image Hashes, then retry.',
        'memory' => 'The server ran out of memory while matching.',
        'stalled' => 'Matching didn’t finish on the server. Retry, or build the photo index under Image Hashes.',
        'not_started' => 'Matching never started in this browser. Reload the page (Ctrl+Shift+R) and retry.',
        'server_error' => 'Server error while matching.',
    ];

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

        if ($this->awaitsMatch($message)) {
            return true;
        }

        return in_array($meta['status'] ?? null, [self::STATUS_NONE, self::STATUS_SUGGESTED], true)
            && $stamp !== null
            && ($meta['stamp'] ?? null) !== $stamp;
    }

    /**
     * Whether the photo has no usable result yet (never matched, matcher upgraded, or a
     * failed attempt is due for an automatic retry). Unlike a stale "none"/"suggested"
     * result, these show a spinner until the match request finishes.
     */
    public function awaitsMatch(ChannelMessage $message): bool
    {
        if (! $this->isEligible($message) || $message->matched_product_id) {
            return false;
        }

        if ($message->image_match_checked_at === null) {
            return true;
        }

        $meta = $this->meta($message);
        if ($meta === []) {
            return false;
        }

        if ((int) ($meta['v'] ?? 0) !== self::META_VERSION) {
            return true;
        }

        return ($meta['status'] ?? null) === self::STATUS_FAILED
            && (int) ($meta['attempts'] ?? 0) < self::MAX_AUTO_ATTEMPTS
            && now()->getTimestamp() >= (int) ($meta['retry_after'] ?? 0);
    }

    /**
     * What the inbox should show for the photo: pending (spinner + match request),
     * stale (show the stored result, re-match silently), stalled (a match attempt or the
     * browser never finished — show the error), or done.
     */
    public function renderState(ChannelMessage $message, ?string $stamp, int $stallSeconds): string
    {
        if ($this->awaitsMatch($message)) {
            $startedAt = (int) ($this->meta($message)['attempt_started_at'] ?? 0);
            if ($startedAt > 0 && now()->getTimestamp() - $startedAt >= $stallSeconds) {
                return self::STATE_STALLED;
            }

            return self::STATE_PENDING;
        }

        return $this->needsMatching($message, $stamp) ? self::STATE_STALE : self::STATE_DONE;
    }

    /**
     * Stored failure for the photo, with admin-facing text.
     *
     * @return array{reason: string, message: string}|null
     */
    public function failure(ChannelMessage $message): ?array
    {
        $meta = $this->meta($message);
        if (($meta['status'] ?? null) !== self::STATUS_FAILED) {
            return null;
        }

        $reason = (string) ($meta['error'] ?? 'server_error');

        return [
            'reason' => $reason,
            'message' => self::failureMessage($reason, $meta['error_detail'] ?? null),
        ];
    }

    public static function failureMessage(string $reason, ?string $detail = null): string
    {
        $message = self::FAILURE_MESSAGES[$reason] ?? self::FAILURE_MESSAGES['server_error'];

        if ($reason === 'server_error' && filled($detail)) {
            $message .= ' '.mb_strimwidth((string) $detail, 0, 160, '…');
        }

        return $message;
    }

    /**
     * Map a PHP fatal error message (seen in a shutdown handler) to a failure reason.
     */
    public static function failureReasonForFatal(string $error): string
    {
        return match (true) {
            str_contains($error, 'Maximum execution time') => 'timeout',
            str_contains($error, 'Allowed memory size') => 'memory',
            default => 'server_error',
        };
    }

    /**
     * Persist a failed attempt so the photo shows an error (and is retried automatically
     * later, a bounded number of times) instead of spinning.
     */
    public function markFailed(ChannelMessage $message, string $reason, ?string $detail = null): void
    {
        $meta = $this->meta($message);
        unset($meta['attempt_started_at']);

        $message->forceFill([
            'matched_product_id' => null,
            'image_match_checked_at' => now(),
            'image_match_meta' => [
                ...$meta,
                'v' => self::META_VERSION,
                'status' => self::STATUS_FAILED,
                'error' => $reason,
                'error_detail' => $detail !== null ? mb_strimwidth($detail, 0, 300, '…') : null,
                'attempts' => max(1, (int) ($meta['attempts'] ?? 0)),
                'retry_after' => now()->addSeconds(self::RETRY_AFTER_SECONDS)->getTimestamp(),
                'rejected' => array_map('intval', $meta['rejected'] ?? []),
            ],
        ])->save();
    }

    /**
     * Staff "Retry": queue the photo for a fresh match on the next render.
     */
    public function resetForRetry(ChannelMessage $message): void
    {
        if (! $this->isEligible($message) || $message->matched_product_id) {
            return;
        }

        $meta = $this->meta($message);
        unset($meta['attempt_started_at'], $meta['error'], $meta['error_detail'], $meta['retry_after']);
        $meta['v'] = self::META_VERSION;
        $meta['status'] = self::STATUS_FAILED;
        $meta['retry_after'] = 0;
        $meta['attempts'] = 0;
        $meta['retries'] = (int) ($meta['retries'] ?? 0) + 1;

        $message->forceFill([
            'image_match_checked_at' => null,
            'image_match_meta' => $meta,
        ])->save();
    }

    /**
     * Changes whenever a new attempt is queued, so the browser never reuses a finished
     * request for a photo that was retried.
     */
    public function attemptKey(ChannelMessage $message): string
    {
        $meta = $this->meta($message);

        return ((int) ($meta['retries'] ?? 0)).'.'.((int) ($meta['attempts'] ?? 0));
    }

    public function indexStamp(): string
    {
        return $this->index->stamp();
    }

    /**
     * Catalog index health for the inbox notice (cached briefly; re-read on every poll otherwise).
     *
     * @return array{total: int, signed: int, unreadable: int, missing: int, cache_writable: bool, index_write_failed: bool}|null
     */
    public function indexHealth(): ?array
    {
        try {
            return Cache::remember('image-match-index-health', now()->addSeconds(30), fn (): array => $this->index->health());
        } catch (Throwable) {
            try {
                return $this->index->health();
            } catch (Throwable $e) {
                Log::warning('Image match index health check failed.', ['error' => $e->getMessage()]);

                return null;
            }
        }
    }

    /**
     * Match the photo if needed and persist the result. Safe to call concurrently: when
     * another request (or the webhook prewarm) is already matching this photo, waits up
     * to $waitSeconds for it, then returns without matching.
     */
    public function resolve(ChannelMessage $message, bool $force = false, int $waitSeconds = 0): ChannelMessage
    {
        if (! $this->isEligible($message)) {
            return $message;
        }

        if (! $force && ! $this->needsMatching($message, $this->index->stamp())) {
            return $message;
        }

        $lock = null;
        try {
            $lock = Cache::lock('channel-image-match:'.$message->id, 120);
            $acquired = $waitSeconds > 0 ? $lock->block($waitSeconds) : $lock->get();
        } catch (LockTimeoutException) {
            $acquired = false;
        } catch (Throwable $e) {
            // A broken cache store must not stop matching; concurrent runs are harmless.
            Log::warning('Inbox image match lock unavailable.', ['message_id' => $message->id, 'error' => $e->getMessage()]);
            $lock = null;
            $acquired = true;
        }

        if (! $acquired) {
            return $message->refresh();
        }

        try {
            $message->refresh();
            if ($force || $this->needsMatching($message, $this->index->stamp())) {
                $this->runMatch($message);
            }
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
            }
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

            $matches = app(self::class);
            try {
                $matches->resolve($message);
            } catch (Throwable $e) {
                Log::warning('Inbound image prewarm match failed.', [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
                try {
                    $matches->markFailed($message->refresh(), 'server_error', $e->getMessage());
                } catch (Throwable) {
                }
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
        $previous = $this->meta($message);
        $rejected = array_map('intval', $previous['rejected'] ?? []);
        $attempts = (int) ($previous['attempts'] ?? 0) + 1;
        $retries = (int) ($previous['retries'] ?? 0);

        // Recorded before the heavy work so a request killed by a PHP time/memory limit
        // still leaves a trace the inbox can turn into an error (see renderState()).
        $message->forceFill([
            'image_match_meta' => [
                ...$previous,
                'attempts' => $attempts,
                'attempt_started_at' => now()->getTimestamp(),
            ],
        ])->save();

        try {
            $this->index->backfillMissingSignatures();
        } catch (Throwable $e) {
            Log::warning('Lazy match signature backfill failed.', ['error' => $e->getMessage()]);
        }
        $stamp = $this->index->stamp();

        $persist = function (string $status, array $candidates = [], string $source = 'visual', ?int $attach = null) use ($message, $started, $stamp, $rejected, $attempts, $retries): void {
            $message->forceFill([
                'matched_product_id' => $attach,
                'image_match_checked_at' => now(),
                'image_match_meta' => [
                    'v' => self::META_VERSION,
                    'status' => $status,
                    'stamp' => $stamp,
                    'source' => $source,
                    'candidates' => $candidates,
                    'rejected' => $rejected,
                    'attempts' => $attempts,
                    'retries' => $retries,
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
            $this->markFailed($message, 'download_failed');

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
            Log::warning('Inbox inbound image match failed.', ['message_id' => $message->id, 'error' => $e->getMessage()]);
            $this->markFailed($message, 'server_error', $e->getMessage());

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
