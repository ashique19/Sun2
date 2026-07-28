<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Facebook\FacebookPageTokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MessengerConversationSyncService
{
    public function __construct(
        private FacebookPageTokenService $tokens,
        private ChannelConversationService $conversations,
        private ChannelOrderDraftService $drafts,
    ) {}

    /**
     * Pull recent Page Messenger conversations from Graph and upsert into Inbox.
     *
     * In Development / Standard Access mode, Meta typically only returns threads
     * involving Admins, Developers, or Testers of the app — not arbitrary customers.
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     conversations: int,
     *     messages: int,
     *     graph_threads: int
     * }
     */
    public function sync(int $conversationLimit = 25, int $messagesPerThread = 30): array
    {
        $lock = Cache::lock('messenger-conversation-sync', 180);

        if (! $lock->get()) {
            return [
                'ok' => true,
                'message' => 'A Messenger sync is already running.',
                'conversations' => 0,
                'messages' => 0,
                'graph_threads' => 0,
            ];
        }

        try {
            return $this->runSync($conversationLimit, $messagesPerThread);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     conversations: int,
     *     messages: int,
     *     graph_threads: int
     * }
     */
    private function runSync(int $conversationLimit, int $messagesPerThread): array
    {
        $token = $this->tokens->token();
        $pageId = $this->tokens->pageId();
        $version = $this->tokens->graphVersion();

        if ($token === '' || $pageId === '') {
            return [
                'ok' => false,
                'message' => 'Facebook Page access token or Page ID is not configured.',
                'conversations' => 0,
                'messages' => 0,
                'graph_threads' => 0,
            ];
        }

        try {
            $response = Http::timeout(30)
                ->withToken($token)
                ->acceptJson()
                ->get('https://graph.facebook.com/'.$version.'/'.$pageId.'/conversations', [
                    'platform' => 'messenger',
                    'limit' => max(1, min(50, $conversationLimit)),
                    'fields' => 'id,updated_time,participants{id,name},messages.limit('
                        .max(1, min(50, $messagesPerThread))
                        .'){id,message,from,created_time,attachments}',
                ]);

            if (! $response->successful()) {
                $error = (string) data_get($response->json(), 'error.message', $response->body());

                return [
                    'ok' => false,
                    'message' => 'Facebook Conversations API failed: '.$error,
                    'conversations' => 0,
                    'messages' => 0,
                    'graph_threads' => 0,
                ];
            }

            $threads = $response->json('data');
            if (! is_array($threads)) {
                $threads = [];
            }

            $conversationCount = 0;
            $messageCount = 0;

            foreach ($threads as $thread) {
                if (! is_array($thread)) {
                    continue;
                }

                $result = $this->ingestThread($thread, $pageId);
                $conversationCount += $result['conversation'] ? 1 : 0;
                $messageCount += $result['messages'];
            }

            $hint = '';
            if ($threads === []) {
                $hint = ' Graph returned 0 threads. In Development mode Meta usually only exposes chats with Admins/Developers/Testers — add the other Facebook account as an app Tester, or switch the app to Live.';
            } elseif ($conversationCount <= 1) {
                $hint = ' Only role-users typically appear while the Meta app is in Development mode. Add other people as Testers, or put the app Live (with pages_messaging Advanced Access) for real customers.';
            }

            return [
                'ok' => true,
                'message' => 'Synced '.$conversationCount.' conversation(s) and '.$messageCount.' message(s) from Facebook ('
                    .count($threads).' Graph threads).'.$hint,
                'conversations' => $conversationCount,
                'messages' => $messageCount,
                'graph_threads' => count($threads),
            ];
        } catch (Throwable $e) {
            Log::warning('Messenger conversation sync failed.', ['message' => $e->getMessage()]);

            return [
                'ok' => false,
                'message' => 'Sync failed: '.$e->getMessage(),
                'conversations' => 0,
                'messages' => 0,
                'graph_threads' => 0,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $thread
     * @return array{conversation: bool, messages: int}
     */
    private function ingestThread(array $thread, string $pageId): array
    {
        $participants = data_get($thread, 'participants.data');
        if (! is_array($participants)) {
            return ['conversation' => false, 'messages' => 0];
        }

        $psid = null;
        $customerName = null;
        foreach ($participants as $participant) {
            if (! is_array($participant)) {
                continue;
            }
            $id = (string) ($participant['id'] ?? '');
            if ($id === '' || $id === $pageId) {
                continue;
            }
            $psid = $id;
            $name = $participant['name'] ?? null;
            $customerName = is_string($name) && trim($name) !== '' ? trim($name) : null;
            break;
        }

        if ($psid === null) {
            return ['conversation' => false, 'messages' => 0];
        }

        $conversation = $this->conversations->findOrCreate(
            ChannelConversation::CHANNEL_MESSENGER,
            $psid,
            [
                'external_account_id' => $pageId,
                'customer_name' => $customerName,
                'meta' => [
                    'page_id' => $pageId,
                    'graph_thread_id' => $thread['id'] ?? null,
                    'synced_from' => 'conversations_api',
                ],
            ],
        );

        if ($customerName && $conversation->customer_name !== $customerName) {
            $conversation->forceFill(['customer_name' => $customerName])->save();
        }

        $messages = data_get($thread, 'messages.data');
        if (! is_array($messages)) {
            $messages = [];
        }

        // Graph returns newest-first; store oldest-first for chronological drafts.
        $messages = array_reverse($messages);
        $stored = 0;
        $storedRecentInbound = false;
        $lookbackHours = max(1, (int) config('channels.ai_draft.lookback_hours', 48));
        $since = now()->subHours($lookbackHours);

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $storedMessage = $this->storeGraphMessage($conversation, $message, $pageId);
            if ($storedMessage === null) {
                continue;
            }

            $stored++;

            if ($storedMessage->direction === ChannelMessage::DIRECTION_INBOUND
                && $storedMessage->sent_at
                && $storedMessage->sent_at->greaterThanOrEqualTo($since)) {
                $storedRecentInbound = true;
            }
        }

        // Inbox can keep historic messages; AI drafts only from recent inbound.
        if ($storedRecentInbound) {
            $this->drafts->syncDraftFromConversation($conversation->fresh(['messages']));
        }

        return ['conversation' => true, 'messages' => $stored];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function storeGraphMessage(ChannelConversation $conversation, array $message, string $pageId): ?ChannelMessage
    {
        $mid = isset($message['id']) ? (string) $message['id'] : null;
        if ($mid === null || $mid === '') {
            return null;
        }

        $existing = ChannelMessage::query()
            ->where('channel_conversation_id', $conversation->id)
            ->where('external_message_id', $mid)
            ->exists();

        if ($existing) {
            return null;
        }

        $fromId = (string) data_get($message, 'from.id', '');
        $direction = ($fromId !== '' && $fromId === $pageId)
            ? ChannelMessage::DIRECTION_OUTBOUND
            : ChannelMessage::DIRECTION_INBOUND;

        $text = isset($message['message']) ? trim((string) $message['message']) : null;
        [$mediaUrl, $mediaMime] = $this->extractAttachment($message);

        if (($text === null || $text === '') && $mediaUrl === null) {
            return null;
        }

        $sentAt = null;
        if (! empty($message['created_time'])) {
            try {
                $sentAt = Carbon::parse((string) $message['created_time']);
            } catch (Throwable) {
                $sentAt = now();
            }
        }

        return $this->conversations->storeMessage($conversation, [
            'external_message_id' => $mid,
            'direction' => $direction,
            'body' => $text !== '' ? $text : null,
            'media_url' => $mediaUrl,
            'media_mime' => $mediaMime,
            'raw_payload' => $message,
            'sent_at' => $sentAt ?? now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{0:?string,1:?string}
     */
    private function extractAttachment(array $message): array
    {
        $attachments = data_get($message, 'attachments.data');
        if (! is_array($attachments)) {
            return [null, null];
        }

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $url = data_get($attachment, 'image_data.url')
                ?? data_get($attachment, 'file_url')
                ?? data_get($attachment, 'video_data.url');

            if (! is_string($url) || $url === '') {
                continue;
            }

            $mime = null;
            if (data_get($attachment, 'image_data')) {
                $mime = 'image/jpeg';
            } elseif (data_get($attachment, 'video_data')) {
                $mime = 'video/mp4';
            }

            return [$url, $mime];
        }

        return [null, null];
    }
}
