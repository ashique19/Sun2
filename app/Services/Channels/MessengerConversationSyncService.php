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
    public const LAST_SYNC_CACHE_KEY = 'messenger.graph_last_synced_at';

    public const POLL_OVERLAP_SECONDS = 30;

    public function __construct(
        private FacebookPageTokenService $tokens,
        private ChannelConversationService $conversations,
    ) {}

    /**
     * Pull recent Page Messenger conversations from Graph and upsert into Inbox.
     *
     * With a Live Meta app + pages_messaging Advanced Access, Graph can return
     * real customer threads. This sync paginates until `$conversationLimit` is reached.
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     conversations: int,
     *     messages: int,
     *     graph_threads: int
     * }
     */
    public function sync(int $conversationLimit = 50, int $messagesPerThread = 30): array
    {
        return $this->withSyncLock(fn () => $this->runSync($conversationLimit, $messagesPerThread));
    }

    /**
     * Cheap catch-up while Inbox is open: list recently updated threads, then
     * fetch messages only for conversations newer than the last successful sync.
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     conversations: int,
     *     messages: int,
     *     graph_threads: int
     * }
     */
    public function poll(): array
    {
        $conversationLimit = max(1, min(25, (int) config('channels.inbox.graph_poll_conversation_limit', 15)));
        $messagesPerThread = max(1, min(15, (int) config('channels.inbox.graph_poll_messages_per_thread', 8)));

        return $this->withSyncLock(fn () => $this->runPoll($conversationLimit, $messagesPerThread));
    }

    /**
     * @param  callable(): array{ok: bool, message: string, conversations: int, messages: int, graph_threads: int}  $callback
     * @return array{ok: bool, message: string, conversations: int, messages: int, graph_threads: int}
     */
    private function withSyncLock(callable $callback): array
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
            return $callback();
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

        $conversationLimit = max(1, min(100, $conversationLimit));
        $messagesPerThread = max(1, min(50, $messagesPerThread));
        $startedAt = now();

        try {
            $fetched = $this->fetchConversationThreads(
                $token,
                $version,
                $pageId,
                $conversationLimit,
                $messagesPerThread,
            );

            if (! $fetched['ok']) {
                return [
                    'ok' => false,
                    'message' => $fetched['message'],
                    'conversations' => 0,
                    'messages' => 0,
                    'graph_threads' => 0,
                ];
            }

            $threads = $fetched['threads'];
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
                $hint = ' Graph returned 0 threads. Confirm the Meta app is Live with pages_messaging Advanced Access, the Page token is valid, and customers have messaged this Page. In Development mode Meta only exposes chats with Admins/Developers/Testers.';
            }

            $this->rememberSyncWatermark($startedAt);

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
     * @return array{ok: bool, message: string, conversations: int, messages: int, graph_threads: int}
     */
    private function runPoll(int $conversationLimit, int $messagesPerThread): array
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

        $startedAt = now();
        $watermark = $this->syncWatermark();

        try {
            $headers = $this->fetchConversationHeaders(
                $token,
                $version,
                $pageId,
                $conversationLimit,
                $watermark,
            );

            if (! $headers['ok']) {
                return [
                    'ok' => false,
                    'message' => $headers['message'],
                    'conversations' => 0,
                    'messages' => 0,
                    'graph_threads' => 0,
                ];
            }

            $freshIds = $headers['ids'];

            if ($freshIds === []) {
                $this->rememberSyncWatermark($startedAt);

                return [
                    'ok' => true,
                    'message' => 'No Messenger conversations newer than the last sync.',
                    'conversations' => 0,
                    'messages' => 0,
                    'graph_threads' => 0,
                ];
            }

            $threads = $this->fetchThreadsByIds($token, $version, $freshIds, $messagesPerThread);

            if (! $threads['ok']) {
                return [
                    'ok' => false,
                    'message' => $threads['message'],
                    'conversations' => 0,
                    'messages' => 0,
                    'graph_threads' => 0,
                ];
            }

            $conversationCount = 0;
            $messageCount = 0;

            foreach ($threads['threads'] as $thread) {
                $result = $this->ingestThread($thread, $pageId);
                $conversationCount += $result['conversation'] ? 1 : 0;
                $messageCount += $result['messages'];
            }

            $this->rememberSyncWatermark($startedAt);

            return [
                'ok' => true,
                'message' => 'Synced '.$conversationCount.' conversation(s) and '.$messageCount.' message(s) from Facebook ('
                    .count($threads['threads']).' Graph threads).',
                'conversations' => $conversationCount,
                'messages' => $messageCount,
                'graph_threads' => count($threads['threads']),
            ];
        } catch (Throwable $e) {
            Log::warning('Messenger conversation poll failed.', ['message' => $e->getMessage()]);

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
     * @return array{ok: bool, message: string, threads: list<array<string, mixed>>}
     */
    private function fetchConversationThreads(
        string $token,
        string $version,
        string $pageId,
        int $conversationLimit,
        int $messagesPerThread,
    ): array {
        $pageSize = min(50, $conversationLimit);
        $fields = 'id,updated_time,participants{id,name},messages.limit('
            .$messagesPerThread
            .'){id,message,from,created_time,attachments}';

        $threads = [];
        $nextUrl = null;
        $maxPages = (int) ceil($conversationLimit / max(1, $pageSize)) + 1;

        for ($page = 0; $page < $maxPages && count($threads) < $conversationLimit; $page++) {
            if ($nextUrl !== null) {
                $response = Http::timeout(30)
                    ->withToken($token)
                    ->acceptJson()
                    ->get($nextUrl);
            } else {
                $response = Http::timeout(30)
                    ->withToken($token)
                    ->acceptJson()
                    ->get('https://graph.facebook.com/'.$version.'/'.$pageId.'/conversations', [
                        'platform' => 'messenger',
                        'limit' => $pageSize,
                        'fields' => $fields,
                    ]);
            }

            if (! $response->successful()) {
                $error = (string) data_get($response->json(), 'error.message', $response->body());

                return [
                    'ok' => false,
                    'message' => 'Facebook Conversations API failed: '.$error,
                    'threads' => [],
                ];
            }

            $batch = $response->json('data');
            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $thread) {
                if (is_array($thread)) {
                    $threads[] = $thread;
                }

                if (count($threads) >= $conversationLimit) {
                    break;
                }
            }

            $next = data_get($response->json(), 'paging.next');
            if (! is_string($next) || $next === '') {
                break;
            }

            $nextUrl = $next;
        }

        return [
            'ok' => true,
            'message' => '',
            'threads' => array_slice($threads, 0, $conversationLimit),
        ];
    }

    /**
     * @return array{ok: bool, message: string, ids: list<string>}
     */
    private function fetchConversationHeaders(
        string $token,
        string $version,
        string $pageId,
        int $conversationLimit,
        ?Carbon $watermark,
    ): array {
        $pageSize = min(25, $conversationLimit);
        $ids = [];
        $nextUrl = null;
        $maxPages = (int) ceil($conversationLimit / max(1, $pageSize)) + 1;
        $hitWatermark = false;

        for ($page = 0; $page < $maxPages && count($ids) < $conversationLimit && ! $hitWatermark; $page++) {
            if ($nextUrl !== null) {
                $response = Http::timeout(20)
                    ->withToken($token)
                    ->acceptJson()
                    ->get($nextUrl);
            } else {
                $response = Http::timeout(20)
                    ->withToken($token)
                    ->acceptJson()
                    ->get('https://graph.facebook.com/'.$version.'/'.$pageId.'/conversations', [
                        'platform' => 'messenger',
                        'limit' => $pageSize,
                        'fields' => 'id,updated_time',
                    ]);
            }

            if (! $response->successful()) {
                $error = (string) data_get($response->json(), 'error.message', $response->body());

                return [
                    'ok' => false,
                    'message' => 'Facebook Conversations API failed: '.$error,
                    'ids' => [],
                ];
            }

            $batch = $response->json('data');
            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $thread) {
                if (! is_array($thread)) {
                    continue;
                }

                $id = isset($thread['id']) ? (string) $thread['id'] : '';
                if ($id === '') {
                    continue;
                }

                $updatedAt = $this->parseGraphTime($thread['updated_time'] ?? null);

                if ($watermark && $updatedAt && $updatedAt->lt($watermark)) {
                    $hitWatermark = true;
                    break;
                }

                $ids[] = $id;

                if (count($ids) >= $conversationLimit) {
                    break;
                }
            }

            if ($hitWatermark || count($ids) >= $conversationLimit) {
                break;
            }

            $next = data_get($response->json(), 'paging.next');
            if (! is_string($next) || $next === '') {
                break;
            }

            $nextUrl = $next;
        }

        return [
            'ok' => true,
            'message' => '',
            'ids' => $ids,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array{ok: bool, message: string, threads: list<array<string, mixed>>}
     */
    private function fetchThreadsByIds(
        string $token,
        string $version,
        array $ids,
        int $messagesPerThread,
    ): array {
        $fields = 'id,updated_time,participants{id,name},messages.limit('
            .$messagesPerThread
            .'){id,message,from,created_time,attachments}';

        $response = Http::timeout(20)
            ->withToken($token)
            ->acceptJson()
            ->get('https://graph.facebook.com/'.$version.'/', [
                'ids' => implode(',', $ids),
                'fields' => $fields,
            ]);

        if (! $response->successful()) {
            $error = (string) data_get($response->json(), 'error.message', $response->body());

            return [
                'ok' => false,
                'message' => 'Facebook Conversations API failed: '.$error,
                'threads' => [],
            ];
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return [
                'ok' => false,
                'message' => 'Facebook Conversations API failed: empty thread payload.',
                'threads' => [],
            ];
        }

        $threads = [];
        foreach ($ids as $id) {
            $thread = $payload[$id] ?? null;
            if (is_array($thread) && ! isset($thread['error'])) {
                $threads[] = $thread;
            }
        }

        return [
            'ok' => true,
            'message' => '',
            'threads' => $threads,
        ];
    }

    private function syncWatermark(): ?Carbon
    {
        $stored = Cache::get(self::LAST_SYNC_CACHE_KEY);
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Carbon::parse($stored)->subSeconds(self::POLL_OVERLAP_SECONDS);
        } catch (Throwable) {
            return null;
        }
    }

    private function rememberSyncWatermark(Carbon $syncedAt): void
    {
        Cache::put(self::LAST_SYNC_CACHE_KEY, $syncedAt->toIso8601String(), now()->addDays(7));
    }

    private function parseGraphTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
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

        // Graph returns newest-first; store oldest-first for chronological Inbox.
        $messages = array_reverse($messages);
        $stored = 0;

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $stored += $this->storeGraphMessage($conversation, $message, $pageId);
        }

        return ['conversation' => true, 'messages' => $stored];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function storeGraphMessage(ChannelConversation $conversation, array $message, string $pageId): int
    {
        $mid = isset($message['id']) ? (string) $message['id'] : null;
        if ($mid === null || $mid === '') {
            return 0;
        }

        $fromId = (string) data_get($message, 'from.id', '');
        $direction = ($fromId !== '' && $fromId === $pageId)
            ? ChannelMessage::DIRECTION_OUTBOUND
            : ChannelMessage::DIRECTION_INBOUND;

        $text = isset($message['message']) ? trim((string) $message['message']) : null;
        $attachments = $this->extractAttachments($message);

        if (($text === null || $text === '') && $attachments === []) {
            return 0;
        }

        $sentAt = null;
        if (! empty($message['created_time'])) {
            try {
                $sentAt = Carbon::parse((string) $message['created_time']);
            } catch (Throwable) {
                $sentAt = now();
            }
        }
        $sentAt ??= now();

        $stored = 0;

        if ($attachments === []) {
            if ($this->messageExists($conversation->id, $mid)) {
                return 0;
            }

            $this->conversations->storeMessage($conversation, [
                'external_message_id' => $mid,
                'direction' => $direction,
                'body' => $text !== '' ? $text : null,
                'media_url' => null,
                'media_mime' => null,
                'raw_payload' => $message,
                'sent_at' => $sentAt,
            ]);

            return 1;
        }

        $total = count($attachments);
        foreach ($attachments as $index => $attachment) {
            $externalId = $this->attachmentExternalId($mid, $index, $total);

            // Legacy first-only ingest stored the first album image under the bare mid.
            if ($total > 1 && $index === 0 && $this->messageExists($conversation->id, $mid)) {
                continue;
            }

            if ($this->messageExists($conversation->id, $externalId)) {
                continue;
            }

            $this->conversations->storeMessage($conversation, [
                'external_message_id' => $externalId,
                'direction' => $direction,
                'body' => $index === 0 && $text !== null && $text !== '' ? $text : null,
                'media_url' => $attachment['url'],
                'media_mime' => $attachment['mime'],
                'raw_payload' => $message,
                'sent_at' => $sentAt,
            ]);
            $stored++;
        }

        return $stored;
    }

    private function messageExists(int $conversationId, string $externalMessageId): bool
    {
        return ChannelMessage::query()
            ->where('channel_conversation_id', $conversationId)
            ->where('external_message_id', $externalMessageId)
            ->exists();
    }

    private function attachmentExternalId(string $mid, int $index, int $total): string
    {
        if ($total === 1) {
            return $mid;
        }

        return $mid.'#'.$index;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array{url: string, mime: ?string}>
     */
    private function extractAttachments(array $message): array
    {
        $attachments = data_get($message, 'attachments.data');
        if (! is_array($attachments)) {
            return [];
        }

        $extracted = [];

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

            $extracted[] = ['url' => $url, 'mime' => $mime];
        }

        return $extracted;
    }
}
