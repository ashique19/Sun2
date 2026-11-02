<?php

namespace App\Services\Admin;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Channels\ChannelReplyService;
use App\Support\PublicImageUpload;
use Throwable;

class InboxProductReplyService
{
    public function __construct(
        private ChannelReplyService $replies,
    ) {}

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function sendPrice(
        ChannelConversation $conversation,
        Product $product,
        ?ChannelMessage $replyTo = null,
    ): array {
        return $this->sendText(
            $conversation,
            $product->priceWithUnitLabel(),
            $replyTo,
            'Failed to send price reply.',
        );
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function sendLink(
        ChannelConversation $conversation,
        Product $product,
        ?ChannelMessage $replyTo = null,
    ): array {
        return $this->sendText(
            $conversation,
            route('product.show', $product),
            $replyTo,
            'Failed to send product link.',
        );
    }

    /**
     * Send every catalog (non-priced) image for the product.
     *
     * @return array{ok: bool, error: ?string, sent: int}
     */
    public function sendAlbum(
        ChannelConversation $conversation,
        Product $product,
        ?ChannelMessage $replyTo = null,
    ): array {
        if (! $product->relationLoaded('images')) {
            $product->load(['images' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);
        }

        $images = $product->catalogImagesExcludingPriced();

        if ($images->isEmpty()) {
            return [
                'ok' => false,
                'error' => 'This product has no catalog images to send.',
                'sent' => 0,
            ];
        }

        $sent = 0;
        foreach ($images as $image) {
            try {
                $upload = PublicImageUpload::fromRelativePath((string) $image->path);
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'sent' => $sent,
                ];
            }

            $result = $this->replies->sendImage(
                $conversation,
                $upload,
                '',
                false,
                $sent === 0 ? $replyTo : null,
            );

            if (! ($result['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => $result['error'] ?? 'Failed to send product image.',
                    'sent' => $sent,
                ];
            }

            $sent++;
        }

        return [
            'ok' => true,
            'error' => null,
            'sent' => $sent,
        ];
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    private function sendText(
        ChannelConversation $conversation,
        string $body,
        ?ChannelMessage $replyTo,
        string $fallbackError,
    ): array {
        $result = $this->replies->sendText($conversation, $body, false, $replyTo);

        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => $result['error'] ?? $fallbackError,
            ];
        }

        return [
            'ok' => true,
            'error' => null,
        ];
    }
}
