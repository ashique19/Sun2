<?php

namespace App\Services\Channels;

use App\Models\ChannelMessage;
use App\Models\Product;
use App\Models\ProductImageMatchMemory;
use App\Models\User;

class ProductImageMatchMemoryService
{
    public function __construct(
        private ChannelInboundMediaService $inboundMedia,
    ) {}

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
    }

    /**
     * Exact-hash memory lookup before Hamming search.
     *
     * @return array{product_id:int,strategy:string,distance:int}|null
     */
    public function lookup(?string $dhash, ?string $dctHash): ?array
    {
        $candidates = [];

        if (is_string($dhash) && $dhash !== '') {
            $candidates[] = ['hash' => $dhash, 'hash_kind' => 'dhash'];
        }

        if (is_string($dctHash) && $dctHash !== '') {
            $candidates[] = ['hash' => $dctHash, 'hash_kind' => 'dct'];
        }

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
            ];
        }

        return null;
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
    }
}
