<?php

namespace App\Services\Channels;

use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Admin\ProductImageHashService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChannelMessageImageMatchService
{
    public function __construct(
        private ProductImageHashService $hasher,
        private ChannelInboundMediaService $media,
    ) {}

    /**
     * Best published catalog match at or above the auto threshold (90%).
     * Uses local heuristics only (full / photo-panel / trim / center crops).
     *
     * @return array{product_id: int, name: string, match_percent: float, strategy: string}|null
     */
    public function bestAutoMatch(ChannelMessage $message): ?array
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND
            || ! $message->isImageAttachment()) {
            return null;
        }

        $cached = $this->media->ensureCached($message);
        if ($cached === null) {
            return null;
        }

        $minBytes = max(1, (int) config('channels.ai_draft.image_min_bytes', 5000));
        if (strlen($cached['bytes']) < $minBytes) {
            return null;
        }

        try {
            $top = $this->hasher->findBestAutoMatchFromBinary($cached['bytes']);
        } catch (Throwable $e) {
            Log::debug('Inbox inbound image hash failed.', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($top === null || (float) $top['match_percent'] < ProductImageHashService::AUTO_MATCH_PERCENT) {
            // Fall back to full-frame / DCT hash compare when crop heuristics miss.
            $top = $this->matchFromCachedHashes($cached);
            if ($top === null || (float) $top['match_percent'] < ProductImageHashService::AUTO_MATCH_PERCENT) {
                return null;
            }
            $top['strategy'] = $top['strategy'] ?? 'cached_hash';
        }

        $productId = (int) $top['product_id'];
        $published = Product::query()
            ->whereKey($productId)
            ->where('is_published', true)
            ->exists();

        if (! $published) {
            return null;
        }

        return [
            'product_id' => $productId,
            'name' => (string) $top['name'],
            'match_percent' => (float) $top['match_percent'],
            'strategy' => (string) ($top['strategy'] ?? 'query_full_vs_catalog_full'),
        ];
    }

    /**
     * Manual staff search fallback for screenshot-heavy inbound photos.
     *
     * @return list<array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int}>
     */
    public function screenshotFallbackMatches(ChannelMessage $message, int $limit = ProductImageHashService::TOP_MATCHES): array
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND
            || ! $message->isImageAttachment()) {
            return [];
        }

        $cached = $this->media->ensureCached($message);
        if ($cached === null) {
            return [];
        }

        $minBytes = max(1, (int) config('channels.ai_draft.image_min_bytes', 5000));
        if (strlen($cached['bytes']) < $minBytes) {
            return [];
        }

        try {
            return $this->hasher->findTopMatchesFromBinary(
                $cached['bytes'],
                $limit,
                ProductImageHashService::MIN_MATCH_PERCENT,
            );
        } catch (Throwable $e) {
            Log::debug('Inbox screenshot fallback image match failed.', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
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

    /**
     * @param  array{bytes: string, mime: string, path: ?string, dhash: ?string, dct_hash: ?string}  $cached
     * @return array{product_id:int,name:string,sku:?string,price:float,stock_quantity:int,image_url:?string,match_percent:float,distance:int,strategy?:string}|null
     */
    private function matchFromCachedHashes(array $cached): ?array
    {
        $best = null;

        foreach (array_filter([$cached['dhash'] ?? null, $cached['dct_hash'] ?? null]) as $hash) {
            $matches = $this->hasher->findTopMatches(
                (string) $hash,
                1,
                ProductImageHashService::AUTO_MATCH_PERCENT,
            );
            $top = $matches[0] ?? null;
            if ($top === null) {
                continue;
            }
            if ($best === null || (float) $top['match_percent'] > (float) $best['match_percent']) {
                $best = $top + ['strategy' => 'cached_hash'];
            }
        }

        return $best;
    }
}
