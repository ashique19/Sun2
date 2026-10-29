<?php

namespace App\Services\Admin;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Channels\ChannelReplyService;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

class InboxPricedImageSender
{
    public function __construct(
        private ChannelReplyService $replies,
        private ProductPricedImageService $pricedImages,
    ) {}

    /**
     * Generate the priced catalog image if needed, then send it as a Messenger reply.
     *
     * @return array{ok: bool, error: ?string, message: ?ChannelMessage}
     */
    public function send(
        ChannelConversation $conversation,
        Product $product,
        ?ChannelMessage $replyTo = null,
        ?int $readerId = null,
    ): array {
        try {
            $pricedPath = is_string($product->priced_image_path) ? trim($product->priced_image_path) : '';
            if ($pricedPath === '' || ! is_file(public_path(ltrim($pricedPath, '/')))) {
                $pricedPath = $this->pricedImages->generate($product);
                $product->refresh();
            }

            $upload = $this->uploadedFileFromPublicPath($pricedPath);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'message' => null,
            ];
        }

        $result = $this->replies->sendImage(
            $conversation,
            $upload,
            '',
            false,
            $replyTo,
        );

        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => $result['error'] ?? 'Failed to send priced image.',
                'message' => $result['message'] ?? null,
            ];
        }

        if ($readerId) {
            $conversation->markRead($readerId);
        }

        return [
            'ok' => true,
            'error' => null,
            'message' => $result['message'] ?? null,
        ];
    }

    private function uploadedFileFromPublicPath(string $relativePath): UploadedFile
    {
        $absolute = public_path(ltrim($relativePath, '/'));

        if (! is_file($absolute) || ! is_readable($absolute)) {
            throw new RuntimeException('Image file is missing on disk.');
        }

        $mime = mime_content_type($absolute) ?: 'image/jpeg';
        if (! str_starts_with($mime, 'image/')) {
            throw new RuntimeException('File is not a valid image.');
        }

        return new UploadedFile(
            $absolute,
            basename($absolute),
            $mime,
            null,
            true,
        );
    }
}
