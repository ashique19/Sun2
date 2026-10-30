<?php

namespace App\Services\Admin;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;

class InboxSnapshotBuilder
{
    public const MAX_THREAD_MESSAGES = 40;

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     window: bool,
     *     messages: list<array{id: int, direction: string, body: string, is_image: bool, media_url: ?string, sent_at_label: string, reply_preview: ?string}>
     * }
     */
    public function thread(ChannelConversation $conversation, ?int $lookbackHours = null): array
    {
        if ($conversation->relationLoaded('messages')) {
            $messages = $conversation->messages
                ->sortBy(fn (ChannelMessage $message) => [$message->sent_at?->timestamp ?? 0, $message->id])
                ->values();

            if ($messages->count() > self::MAX_THREAD_MESSAGES) {
                $messages = $messages->slice(-1 * self::MAX_THREAD_MESSAGES)->values();
            }
        } else {
            $hours = max(1, $lookbackHours ?? (int) config('channels.inbox.thread_lookback_hours', 24));
            $since = now()->subHours($hours);

            $messages = ChannelMessage::query()
                ->with('replyTo:id,body,media_url,media_path,media_mime')
                ->where('channel_conversation_id', $conversation->id)
                ->where(function ($window) use ($since) {
                    $window->where('sent_at', '>=', $since)
                        ->orWhereNull('sent_at');
                })
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->limit(self::MAX_THREAD_MESSAGES)
                ->get()
                ->reverse()
                ->values();
        }

        return [
            'id' => (int) $conversation->id,
            'name' => $this->displayName($conversation),
            'window' => $conversation->isWithinMessagingWindow(),
            'messages' => $messages->map(fn (ChannelMessage $message) => $this->message($message))->all(),
        ];
    }

    /**
     * @return array{id: int, direction: string, body: string, is_image: bool, media_url: ?string, sent_at_label: string, reply_preview: ?string}
     */
    public function message(ChannelMessage $message): array
    {
        $body = trim((string) ($message->body ?? ''));
        if (mb_strlen($body) > 2000) {
            $body = mb_substr($body, 0, 2000);
        }

        $isImage = $message->isImageAttachment();

        return [
            'id' => (int) $message->id,
            'direction' => (string) $message->direction,
            'body' => $body,
            'is_image' => $isImage,
            'media_url' => $isImage ? route('admin.inbox.media', $message) : null,
            'sent_at_label' => $message->sent_at
                ? $message->sent_at->timezone('Asia/Dhaka')->format('h:i A')
                : '',
            'reply_preview' => $message->replyTo?->previewText(60),
        ];
    }

    private function displayName(ChannelConversation $conversation): string
    {
        $name = trim((string) ($conversation->customer_name ?: $conversation->customer_phone ?: $conversation->external_user_id));

        return $name !== '' ? $name : 'Conversation';
    }
}
