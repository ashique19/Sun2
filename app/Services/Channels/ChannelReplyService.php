<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Facebook\FacebookPageTokenService;
use App\Support\StorefrontAssets;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ChannelReplyService
{
    public function __construct(
        private ChannelConversationService $conversations,
        private FacebookPageTokenService $tokens,
    ) {}

    /**
     * Send a free-form text reply within the 24h customer care window.
     *
     * @return array{ok:bool,message:?ChannelMessage,error:?string,outside_window:bool}
     */
    public function sendText(
        ChannelConversation $conversation,
        string $text,
        bool $force = false,
        ?ChannelMessage $replyTo = null,
    ): array {
        return $this->send($conversation, trim($text), null, $force, $replyTo);
    }

    /**
     * Send an image (optional caption) within the 24h customer care window.
     *
     * @return array{ok:bool,message:?ChannelMessage,error:?string,outside_window:bool}
     */
    public function sendImage(
        ChannelConversation $conversation,
        UploadedFile $image,
        string $caption = '',
        bool $force = false,
        ?ChannelMessage $replyTo = null,
    ): array {
        return $this->send($conversation, trim($caption), $image, $force, $replyTo);
    }

    /**
     * Sync read state to Facebook: claim the thread from Page Inbox (so Meta
     * Business Suite / Page Inbox stops showing it unread) and send mark_seen
     * (customer-facing seen receipts).
     *
     * Returns true when Graph accepted mark_seen, or when there was nothing left to sync.
     */
    public function markSeen(ChannelConversation $conversation): bool
    {
        if ($conversation->channel !== ChannelConversation::CHANNEL_MESSENGER) {
            return true;
        }

        $token = $this->tokens->token();
        $psid = trim((string) $conversation->external_user_id);
        if ($token === '' || $psid === '') {
            Log::warning('Messenger mark_seen skipped: missing page token or PSID.', [
                'conversation_id' => $conversation->id,
                'has_token' => $token !== '',
                'psid' => $psid,
            ]);

            return false;
        }

        try {
            $needsSeen = $conversation->needsMessengerSeenSync();

            // Page Inbox keeps threads unread until our app owns the conversation.
            // Always attempt take when seen is pending; otherwise throttle take retries.
            if ($needsSeen || $this->shouldRetryThreadTakeover($conversation)) {
                $this->takeThreadControl($conversation, $psid, $token);
            }

            if (! $conversation->needsMessengerSeenSync()) {
                return true;
            }

            $ok = $this->postMarkSeen($conversation, $psid, $token);

            if (! $ok) {
                // request_thread_control is async; still try mark_seen again in case
                // ownership already flipped, then poll/open will retry later.
                $this->requestThreadControl($conversation, $psid, $token);
                $ok = $this->postMarkSeen($conversation, $psid, $token);
            }

            if (! $ok && $this->takeThreadControl($conversation, $psid, $token)) {
                $ok = $this->postMarkSeen($conversation, $psid, $token);
            }

            if ($ok) {
                $conversation->forceFill([
                    'messenger_seen_at' => $conversation->last_inbound_at?->copy() ?? now(),
                ])->save();
                Cache::forget($this->markSeenErrorCacheKey($conversation));
            }

            return $ok;
        } catch (Throwable $e) {
            Log::warning('Messenger mark_seen exception.', [
                'conversation_id' => $conversation->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function postMarkSeen(ChannelConversation $conversation, string $psid, string $token): bool
    {
        $lastError = null;

        foreach ($this->messagesEndpoints() as $url) {
            $response = Http::timeout(12)
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'recipient' => ['id' => $psid],
                    'sender_action' => 'mark_seen',
                ]);

            if ($response->successful()) {
                return true;
            }

            $lastError = $response->body();

            Log::warning('Messenger mark_seen failed.', [
                'conversation_id' => $conversation->id,
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        if (is_string($lastError) && $lastError !== '') {
            Cache::put($this->markSeenErrorCacheKey($conversation), $lastError, now()->addMinutes(30));
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function messagesEndpoints(): array
    {
        $version = $this->tokens->graphVersion();
        $pageId = $this->tokens->pageId();
        $endpoints = [];

        // Prefer /{page-id}/messages (current Meta docs), then always fall back to
        // /me/messages — Send API already uses /me, and a mismatched PAGE_ID must
        // not leave mark_seen broken while replies still work.
        if ($pageId !== '') {
            $endpoints[] = 'https://graph.facebook.com/'.$version.'/'.$pageId.'/messages';
        }

        $endpoints[] = 'https://graph.facebook.com/'.$version.'/me/messages';

        return array_values(array_unique($endpoints));
    }

    public function lastMarkSeenError(ChannelConversation $conversation): ?string
    {
        $value = Cache::get($this->markSeenErrorCacheKey($conversation));

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function markSeenErrorCacheKey(ChannelConversation $conversation): string
    {
        return 'messenger.mark_seen.last_error.'.$conversation->id;
    }

    private function shouldRetryThreadTakeover(ChannelConversation $conversation): bool
    {
        return ! Cache::has('messenger.take_ok.'.$conversation->id)
            && ! Cache::has('messenger.take_attempt.'.$conversation->id);
    }

    private function rememberThreadTakeover(ChannelConversation $conversation): void
    {
        Cache::put('messenger.take_ok.'.$conversation->id, 1, now()->addMinutes(10));
        Cache::forget('messenger.take_attempt.'.$conversation->id);
    }

    private function rememberThreadTakeAttempt(ChannelConversation $conversation): void
    {
        Cache::put('messenger.take_attempt.'.$conversation->id, 1, now()->addMinutes(2));
    }

    /**
     * Claim the thread from Page Inbox / another app so unread clears in FB Messages.
     */
    private function takeThreadControl(ChannelConversation $conversation, string $psid, string $token): bool
    {
        $ok = $this->postThreadControl(
            $conversation,
            $psid,
            $token,
            'take_thread_control',
            'Admin Inbox sync for conversation #'.$conversation->id,
        );

        if ($ok) {
            $this->rememberThreadTakeover($conversation);
        } else {
            $this->rememberThreadTakeAttempt($conversation);
        }

        return $ok;
    }

    /**
     * Ask the current owner to hand over control (Conversation Routing / handover).
     */
    private function requestThreadControl(ChannelConversation $conversation, string $psid, string $token): bool
    {
        return $this->postThreadControl(
            $conversation,
            $psid,
            $token,
            'request_thread_control',
            'Admin Inbox requested control for conversation #'.$conversation->id,
        );
    }

    private function postThreadControl(
        ChannelConversation $conversation,
        string $psid,
        string $token,
        string $edge,
        string $metadata,
    ): bool {
        $pageId = $this->tokens->pageId();
        if ($pageId === '') {
            Log::warning('Messenger '.$edge.' skipped: FACEBOOK_PAGE_ID is not configured.', [
                'conversation_id' => $conversation->id,
            ]);

            return false;
        }

        $version = $this->tokens->graphVersion();

        try {
            $response = Http::timeout(12)
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->post('https://graph.facebook.com/'.$version.'/'.$pageId.'/'.$edge, [
                    'recipient' => ['id' => $psid],
                    'metadata' => $metadata,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Messenger '.$edge.' failed.', [
                'conversation_id' => $conversation->id,
                'page_id' => $pageId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Messenger '.$edge.' exception.', [
                'conversation_id' => $conversation->id,
                'message' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * @return array{ok:bool,message:?ChannelMessage,error:?string,outside_window:bool}
     */
    private function send(
        ChannelConversation $conversation,
        string $text,
        ?UploadedFile $image,
        bool $force,
        ?ChannelMessage $replyTo,
    ): array {
        if ($text === '' && $image === null) {
            return [
                'ok' => false,
                'message' => null,
                'error' => 'Reply text or image is required.',
                'outside_window' => false,
            ];
        }

        if (! $force && ! $conversation->isWithinMessagingWindow()) {
            return [
                'ok' => false,
                'message' => null,
                'error' => 'Outside the 24-hour messaging window. Customer must message first.',
                'outside_window' => true,
            ];
        }

        if ($replyTo && (int) $replyTo->channel_conversation_id !== (int) $conversation->id) {
            return [
                'ok' => false,
                'message' => null,
                'error' => 'Reply target is not part of this conversation.',
                'outside_window' => false,
            ];
        }

        $storedPath = null;
        $mediaMime = null;
        $publicUrl = null;

        try {
            if ($image) {
                [$storedPath, $mediaMime, $publicUrl] = $this->persistOutboundImage($conversation, $image);
            }

            $externalId = match ($conversation->channel) {
                ChannelConversation::CHANNEL_MESSENGER => $this->sendMessenger(
                    $conversation,
                    $text,
                    $publicUrl,
                    $replyTo,
                ),
                default => throw new RuntimeException('Unsupported channel for outbound replies: '.$conversation->channel),
            };

            $stored = $this->conversations->storeMessage($conversation, [
                'external_message_id' => $externalId,
                'direction' => ChannelMessage::DIRECTION_OUTBOUND,
                'body' => $text !== '' ? $text : null,
                'media_url' => $storedPath,
                'media_mime' => $mediaMime,
                'reply_to_message_id' => $replyTo?->id,
                'sent_at' => now(),
                'raw_payload' => array_filter([
                    'text' => $text !== '' ? $text : null,
                    'media_url' => $storedPath,
                    'reply_to_message_id' => $replyTo?->id,
                    'reply_to_external_id' => $replyTo?->external_message_id,
                ], fn ($v) => $v !== null),
            ]);

            // mark_seen is deferred by the Inbox UI so Send API latency is not doubled.

            return [
                'ok' => true,
                'message' => $stored,
                'error' => null,
                'outside_window' => false,
            ];
        } catch (Throwable $e) {
            if (is_string($storedPath) && $storedPath !== '') {
                $absolute = public_path(ltrim($storedPath, '/'));
                if (is_file($absolute)) {
                    @unlink($absolute);
                }
            }

            Log::warning('Channel reply failed.', [
                'conversation_id' => $conversation->id,
                'channel' => $conversation->channel,
                'message' => $e->getMessage(),
            ]);

            $message = $e->getMessage();
            if (str_contains(strtolower($message), 'access token')
                || str_contains(strtolower($message), 'oauth')
                || str_contains(strtolower($message), 'session has expired')) {
                $this->tokens->invalidateStatusCache();
            }

            return [
                'ok' => false,
                'message' => null,
                'error' => $message,
                'outside_window' => false,
            ];
        }
    }

    /**
     * @return array{0:string,1:string,2:string} relative path, mime, absolute public URL for Meta
     */
    private function persistOutboundImage(ChannelConversation $conversation, UploadedFile $image): array
    {
        $mime = (string) ($image->getMimeType() ?: 'image/jpeg');
        if (! str_starts_with($mime, 'image/')) {
            throw new RuntimeException('Only image attachments are supported.');
        }

        $directory = public_path('img/channel-replies/'.$conversation->id);
        File::ensureDirectoryExists($directory);

        $extension = strtolower($image->getClientOriginalExtension() ?: $image->extension() ?: 'jpg');
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $extension = 'jpg';
        }

        $filename = now()->format('YmdHis').'_'.Str::lower(Str::random(8)).'.'.$extension;
        $absolute = $directory.DIRECTORY_SEPARATOR.$filename;

        $source = $image->getRealPath();
        if (! $source || ! is_readable($source)) {
            throw new RuntimeException('Uploaded file is not readable.');
        }

        if (! @File::copy($source, $absolute)) {
            $contents = file_get_contents($source);
            if ($contents === false || ! File::put($absolute, $contents)) {
                throw new RuntimeException('Could not save uploaded image.');
            }
        }

        $relative = 'img/channel-replies/'.$conversation->id.'/'.$filename;
        $publicUrl = StorefrontAssets::url($relative) ?: url($relative);

        if (! str_starts_with($publicUrl, 'http://') && ! str_starts_with($publicUrl, 'https://')) {
            $publicUrl = url($relative);
        }

        return [$relative, $mime, $publicUrl];
    }

    private function sendMessenger(
        ChannelConversation $conversation,
        string $text,
        ?string $imageUrl,
        ?ChannelMessage $replyTo,
    ): ?string {
        $token = $this->tokens->token();
        if ($token === '') {
            throw new RuntimeException('FACEBOOK_PAGE_ACCESS_TOKEN is not configured.');
        }

        $version = $this->tokens->graphVersion();
        $url = 'https://graph.facebook.com/'.$version.'/me/messages';

        $message = [];
        if ($imageUrl) {
            $message['attachment'] = [
                'type' => 'image',
                'payload' => [
                    'url' => $imageUrl,
                    'is_reusable' => true,
                ],
            ];
            // Messenger does not allow text + attachment in one message; send caption as separate text if needed.
        } else {
            $message['text'] = $text;
        }

        // reply_to is a top-level Send API field (sibling of message), not inside message.
        $payload = [
            'recipient' => ['id' => $conversation->external_user_id],
            'messaging_type' => 'RESPONSE',
            'message' => $message,
        ];
        $replyToMid = $this->messengerReplyMid($replyTo);
        if ($replyToMid) {
            $payload['reply_to'] = ['mid' => $replyToMid];
        }

        $response = $this->postMessengerSend($url, $token, $payload);

        // Meta often rejects reply_to for image/album mids (opaque 2018012 / invalid mid).
        // Retry once without reply_to; local reply_to_message_id is still stored for inbox UI.
        if (! $response->successful() && isset($payload['reply_to'])) {
            Log::warning('Messenger Send API failed with reply_to; retrying without it.', [
                'conversation_id' => $conversation->id,
                'reply_to_mid' => $replyToMid,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            unset($payload['reply_to']);
            $replyToMid = null;
            $response = $this->postMessengerSend($url, $token, $payload);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Messenger Send API error ('.$response->status().'): '.$response->body());
        }

        $mid = $response->json('message_id');
        $mid = is_string($mid) && $mid !== '' ? $mid : null;

        // If we sent an image with a caption, follow up with the text (same reply thread).
        if ($imageUrl && $text !== '') {
            $captionPayload = [
                'recipient' => ['id' => $conversation->external_user_id],
                'messaging_type' => 'RESPONSE',
                'message' => ['text' => $text],
            ];
            if ($mid) {
                $captionPayload['reply_to'] = ['mid' => $mid];
            } elseif ($replyToMid) {
                $captionPayload['reply_to'] = ['mid' => $replyToMid];
            }

            $captionResponse = $this->postMessengerSend($url, $token, $captionPayload);

            if (! $captionResponse->successful() && isset($captionPayload['reply_to'])) {
                Log::warning('Messenger caption follow-up failed with reply_to; retrying without it.', [
                    'conversation_id' => $conversation->id,
                    'body' => $captionResponse->body(),
                ]);
                unset($captionPayload['reply_to']);
                $captionResponse = $this->postMessengerSend($url, $token, $captionPayload);
            }

            if (! $captionResponse->successful()) {
                Log::warning('Messenger caption follow-up failed.', [
                    'conversation_id' => $conversation->id,
                    'body' => $captionResponse->body(),
                ]);
            }
        }

        return $mid;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postMessengerSend(string $url, string $token, array $payload): Response
    {
        return Http::timeout(30)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);
    }

    private function messengerReplyMid(?ChannelMessage $message): ?string
    {
        if (! $message) {
            return null;
        }

        $rawWebhookMid = data_get($message->raw_payload, 'message.mid');
        if (is_string($rawWebhookMid) && trim($rawWebhookMid) !== '') {
            return trim($rawWebhookMid);
        }

        $rawGraphMid = data_get($message->raw_payload, 'id');
        if (is_string($rawGraphMid) && trim($rawGraphMid) !== '') {
            return trim($rawGraphMid);
        }

        $externalMessageId = trim((string) ($message->external_message_id ?? ''));
        if ($externalMessageId === '') {
            return null;
        }

        return preg_replace('/#\d+$/', '', $externalMessageId) ?: null;
    }
}
