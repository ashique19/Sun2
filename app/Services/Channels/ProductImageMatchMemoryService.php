<?php

namespace App\Services\Channels;

use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImageMatchMemory;
use App\Models\User;
use App\Services\Admin\ProductImageHashService;

class ProductImageMatchMemoryService
{
    /**
     * Bits of Hamming slack for a staff tag. 6/64 is 90.6%, the same bar as auto-match,
     * so a recompressed copy of a photo staff already tagged still resolves.
     */
    public const NEAR_DISTANCE = 6;

    /** @var list<array{id: int, hash: string, hash_kind: string, product_id: int, hit_count: int}>|null */
    private static ?array $cachedMemories = null;

    public function __construct(
        private ChannelInboundMediaService $inboundMedia,
        private ProductImageHashService $hasher,
    ) {}

    public static function flush(): void
    {
        self::$cachedMemories = null;
    }

    /**
     * Persist staff Tag corrections as exact hash → product_id memories.
     */
    public function rememberFromStaffTag(ChannelMessage $message, Product $product, ?User $user = null): void
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND
            || ! $message->isImageAttachment()) {
            return;
        }

        $cached = $this->inboundMedia->ensureCached($message);
        if ($cached === null) {
            return;
        }

        $message->refresh();

        $this->upsertHash($cached['dhash'] ?? $message->media_dhash, 'dhash', $product, $message, $user);
        $this->upsertHash($cached['dct_hash'] ?? $message->media_dct_hash, 'dct', $product, $message, $user);
    }

    /**
     * Forget memories created from this message's hashes (staff clear tag).
     */
    public function forgetFromMessage(ChannelMessage $message): void
    {
        if ($message->direction !== ChannelMessage::DIRECTION_INBOUND
            || ! $message->isImageAttachment()) {
            return;
        }

        $cached = $this->inboundMedia->ensureCached($message);
        $message->refresh();

        $dhash = $cached['dhash'] ?? $message->media_dhash;
        $dct = $cached['dct_hash'] ?? $message->media_dct_hash;

        $pairs = [];

        if (is_string($dhash) && $dhash !== '') {
            $pairs[] = ['hash' => $dhash, 'hash_kind' => 'dhash'];
        }

        if (is_string($dct) && $dct !== '') {
            $pairs[] = ['hash' => $dct, 'hash_kind' => 'dct'];
        }

        foreach ($pairs as $pair) {
            ProductImageMatchMemory::query()
                ->where('hash', $pair['hash'])
                ->where('hash_kind', $pair['hash_kind'])
                ->where('source_channel_message_id', $message->id)
                ->delete();
        }

        self::flush();
    }

    /**
     * Exact hash first, then a near hash of a previous staff tag.
     *
     * @return array{product_id:int,strategy:string,distance:int,match_percent:float}|null
     */
    public function lookup(?string $dhash, ?string $dctHash): ?array
    {
        $exact = $this->exactLookup($dhash, $dctHash);
        if ($exact !== null) {
            return $exact;
        }

        return $this->nearLookup($dhash, $dctHash, self::NEAR_DISTANCE);
    }

    /**
     * @return array{product_id:int,strategy:string,distance:int,match_percent:float}|null
     */
    private function exactLookup(?string $dhash, ?string $dctHash): ?array
    {
        $candidates = $this->hashCandidates($dhash, $dctHash);

        foreach ($candidates as $candidate) {
            $memory = ProductImageMatchMemory::query()
                ->where('hash', $candidate['hash'])
                ->where('hash_kind', $candidate['hash_kind'])
                ->first();

            if ($memory === null) {
                continue;
            }

            $memory->forceFill([
                'hit_count' => ((int) $memory->hit_count) + 1,
                'last_hit_at' => now(),
            ])->save();

            return [
                'product_id' => (int) $memory->product_id,
                'strategy' => 'staff_memory',
                'distance' => 0,
                'match_percent' => 100.0,
            ];
        }

        return null;
    }

    /**
     * @return array{product_id:int,strategy:string,distance:int,match_percent:float}|null
     */
    private function nearLookup(?string $dhash, ?string $dctHash, int $maxDistance): ?array
    {
        $candidates = $this->hashCandidates($dhash, $dctHash);
        if ($candidates === []) {
            return null;
        }

        $best = null;
        $bestDistance = $maxDistance + 1;

        foreach ($this->memories() as $memory) {
            foreach ($candidates as $candidate) {
                if ($memory['hash_kind'] !== $candidate['hash_kind']) {
                    continue;
                }

                $distance = $this->hasher->hammingDistance($candidate['hash'], $memory['hash']);
                if ($distance === 0 || $distance > $maxDistance) {
                    continue;
                }

                $closer = $distance < $bestDistance;
                $tiePreferred = $distance === $bestDistance && $memory['hit_count'] > (int) ($best['hit_count'] ?? -1);
                if ($best === null || $closer || $tiePreferred) {
                    $bestDistance = $distance;
                    $best = $memory;
                }
            }
        }

        if ($best === null) {
            return null;
        }

        ProductImageMatchMemory::query()->whereKey($best['id'])->update([
            'hit_count' => ((int) $best['hit_count']) + 1,
            'last_hit_at' => now(),
        ]);
        self::flush();

        return [
            'product_id' => (int) $best['product_id'],
            'strategy' => 'staff_memory',
            'distance' => $bestDistance,
            'match_percent' => round(max(0, (1 - ($bestDistance / ProductImageHashService::HASH_BITS)) * 100), 1),
        ];
    }

    /**
     * @return list<array{hash: string, hash_kind: string}>
     */
    private function hashCandidates(?string $dhash, ?string $dctHash): array
    {
        $candidates = [];

        if (is_string($dhash) && $dhash !== '') {
            $candidates[] = ['hash' => $dhash, 'hash_kind' => 'dhash'];
        }

        if (is_string($dctHash) && $dctHash !== '') {
            $candidates[] = ['hash' => $dctHash, 'hash_kind' => 'dct'];
        }

        return $candidates;
    }

    /**
     * @return list<array{id: int, hash: string, hash_kind: string, product_id: int, hit_count: int}>
     */
    private function memories(): array
    {
        if (self::$cachedMemories !== null) {
            return self::$cachedMemories;
        }

        self::$cachedMemories = ProductImageMatchMemory::query()
            ->get(['id', 'hash', 'hash_kind', 'product_id', 'hit_count'])
            ->map(fn (ProductImageMatchMemory $memory): array => [
                'id' => (int) $memory->id,
                'hash' => (string) $memory->hash,
                'hash_kind' => (string) $memory->hash_kind,
                'product_id' => (int) $memory->product_id,
                'hit_count' => (int) $memory->hit_count,
            ])
            ->all();

        return self::$cachedMemories;
    }

    private function upsertHash(
        ?string $hash,
        string $kind,
        Product $product,
        ChannelMessage $message,
        ?User $user,
    ): void {
        if (! is_string($hash) || $hash === '') {
            return;
        }

        ProductImageMatchMemory::query()->updateOrCreate(
            [
                'hash' => $hash,
                'hash_kind' => $kind,
            ],
            [
                'product_id' => $product->id,
                'source_channel_message_id' => $message->id,
                'created_by' => $user?->id,
            ],
        );

        self::flush();
    }
}
