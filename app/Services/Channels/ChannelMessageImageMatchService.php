<?php

namespace App\Services\Channels;

use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Admin\ProductImageCatalogIndex;
use App\Services\Admin\ProductImageEmbeddingService;
use App\Services\Admin\ProductImageHashService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChannelMessageImageMatchService
{
    public function __construct(
        private ProductImageHashService $hasher,
        private ChannelInboundMediaService $media,
        private ProductImageMatchMemoryService $matchMemory,
        private ProductImageEmbeddingService $embeddings,
        private ProductImageCatalogIndex $catalog,
    ) {}

    /**
     * Best published catalog match at or above the auto threshold (90%).
     * Order: staff memory → local hash crops → cached hashes → GD embedding.
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

        $memory = $this->matchMemory->lookup(
            $cached['dhash'] ?? null,
            $cached['dct_hash'] ?? null,
        );

        if ($memory !== null) {
            $remembered = $this->publishedMatch(
                (int) $memory['product_id'],
                (float) ($memory['match_percent'] ?? 100.0),
                'staff_memory',
            );

            if ($remembered !== null) {
                return $remembered;
            }
        }

        $minBytes = max(1, (int) config('channels.ai_draft.image_min_bytes', 5000));
        if (strlen($cached['bytes']) < $minBytes) {
            return null;
        }

        $cacheKey = 'inbox-image-auto-match:v1:'.sha1($cached['bytes'].'|'.$this->catalog->matchCacheFingerprint());
        $stored = Cache::get($cacheKey);
        if (is_array($stored) && array_key_exists('match', $stored)) {
            return $this->hydrateCachedMatch($stored['match']);
        }

        $match = $this->computeAutoMatch($message, $cached);
        Cache::put($cacheKey, ['match' => $match], now()->addDays(14));

        return $match;
    }

    /**
     * @param  array{bytes: string, mime: string, path: ?string, dhash: ?string, dct_hash: ?string}  $cached
     * @return array{product_id: int, name: string, match_percent: float, strategy: string}|null
     */
    private function computeAutoMatch(ChannelMessage $message, array $cached): ?array
    {
        try {
            $top = $this->hasher->findBestAutoMatchFromBinary($cached['bytes']);
        } catch (Throwable $e) {
            Log::debug('Inbox inbound image hash failed.', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            $top = null;
        }

        if ($top === null || (float) $top['match_percent'] < ProductImageHashService::autoMatchPercent()) {
            // Fall back to full-frame / DCT hash compare when crop heuristics miss.
            $top = $this->matchFromCachedHashes($cached);
            if ($top === null || (float) $top['match_percent'] < ProductImageHashService::autoMatchPercent()) {
                $top = $this->matchFromEmbedding($cached['bytes']);
                if ($top === null) {
                    return null;
                }
            } else {
                $top['strategy'] = $top['strategy'] ?? 'cached_hash';
            }
        }

        return $this->publishedMatch(
            (int) $top['product_id'],
            (float) $top['match_percent'],
            (string) ($top['strategy'] ?? 'query_full_vs_catalog_full'),
        );
    }

    /**
     * @return array{product_id: int, name: string, match_percent: float, strategy: string}|null
     */
    private function hydrateCachedMatch(mixed $match): ?array
    {
        if (! is_array($match) || ! isset($match['product_id'])) {
            return null;
        }

        return $this->publishedMatch(
            (int) $match['product_id'],
            (float) ($match['match_percent'] ?? 0),
            (string) ($match['strategy'] ?? 'cached'),
        );
    }

    /**
     * @return array{product_id: int, name: string, match_percent: float, strategy: string}|null
     */
    private function publishedMatch(int $productId, float $matchPercent, string $strategy): ?array
    {
        $product = Product::query()
            ->whereKey($productId)
            ->where('is_published', true)
            ->first();

        if ($product === null) {
            return null;
        }

        return [
            'product_id' => (int) $product->id,
            'name' => (string) $product->name,
            'match_percent' => $matchPercent,
            'strategy' => $strategy,
        ];
    }

    /**
     * @return array{product_id:int,name:string,match_percent:float,strategy:string}|null
     */
    private function matchFromEmbedding(string $bytes): ?array
    {
        try {
            $match = $this->embeddings->findBestAutoMatchFromBinary($bytes);
            if ($match !== null) {
                return $match;
            }

            foreach ($this->hasher->contentCropsForEmbedding($bytes) as $crop) {
                try {
                    $match = $this->embeddings->findBestAutoMatchFromGdImage($crop, 'embedding_content');
                } finally {
                    imagedestroy($crop);
                }

                if ($match !== null) {
                    return $match;
                }
            }
        } catch (Throwable $e) {
            Log::debug('Inbox inbound embedding match failed.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return null;
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
                ProductImageHashService::minMatchPercent(),
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

        $autoPercent = ProductImageHashService::autoMatchPercent();

        foreach ([
            ['hash' => $cached['dhash'] ?? null, 'kind' => 'dhash'],
            ['hash' => $cached['dct_hash'] ?? null, 'kind' => 'dct'],
        ] as $candidate) {
            if (! is_string($candidate['hash']) || $candidate['hash'] === '') {
                continue;
            }

            $matches = $this->hasher->findTopMatches(
                $candidate['hash'],
                1,
                $autoPercent,
                $candidate['kind'],
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
