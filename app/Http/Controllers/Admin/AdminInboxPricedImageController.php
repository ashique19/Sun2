<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Admin\InboxPricedImageSender;
use App\Support\AdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminInboxPricedImageController extends Controller
{
    /**
     * Send one priced product image from the inbox picker / match chip.
     *
     * Runs outside Livewire so staff can queue several P.img clicks while Facebook
     * is still uploading the previous image.
     */
    public function __invoke(Request $request, InboxPricedImageSender $sender): JsonResponse
    {
        AdminAccess::ensureStaffAdmin();

        $validated = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'reply_to_message_id' => ['nullable', 'integer'],
        ]);

        $conversation = ChannelConversation::query()->find((int) $validated['conversation_id']);
        if (! $conversation) {
            return response()->json([
                'ok' => false,
                'error' => 'Conversation not found.',
            ], 404);
        }

        $product = Product::query()->find((int) $validated['product_id']);
        if (! $product) {
            return response()->json([
                'ok' => false,
                'error' => 'Product not found.',
            ], 404);
        }

        $replyTo = null;
        $replyToId = (int) ($validated['reply_to_message_id'] ?? 0);
        if ($replyToId > 0) {
            $replyTo = ChannelMessage::query()
                ->where('channel_conversation_id', $conversation->id)
                ->whereKey($replyToId)
                ->first();

            if (! $replyTo) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Reply target is not part of this conversation.',
                ], 422);
            }
        }

        $result = $sender->send(
            $conversation,
            $product,
            $replyTo,
            auth()->id(),
        );

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'error' => $result['error'] ?? null,
            'product_id' => $product->id,
            'conversation_id' => $conversation->id,
        ], ($result['ok'] ?? false) ? 200 : 422);
    }
}
