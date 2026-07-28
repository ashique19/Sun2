<?php

namespace App\Livewire\Admin;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Channels\ChannelInboxDiagnostics;
use App\Services\Channels\ChannelInboxPurgeService;
use App\Services\Channels\ChannelMessageOrderMapper;
use App\Services\Channels\ChannelOrderDraftService;
use App\Services\Channels\ChannelReplyService;
use App\Services\Channels\MessengerConversationSyncService;
use App\Support\AdminAccess;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Title('Admin Inbox')]
#[Layout('components.layouts.admin')]
class AdminInbox extends Component
{
    use WithFileUploads;

    #[Url]
    public string $channel = '';

    #[Url]
    public string $unread = '';

    #[Url]
    public string $window = '';

    #[Url]
    public string $linked = '';

    #[Url(as: 'conversation', history: true)]
    public ?int $selectedConversationId = null;

    public string $replyText = '';

    public ?int $replyToMessageId = null;

    /** @var TemporaryUploadedFile|null */
    public $replyImage = null;

    public ?string $error = null;

    public ?string $statusMessage = null;

    /**
     * On small screens the inbox is a single pane: list or thread.
     * Desktop (xl+) always shows both columns.
     */
    public bool $mobileThreadOpen = false;

    /**
     * Filter selects stay collapsed behind a toggle on small screens.
     * Desktop (xl+) always shows them inline.
     */
    public bool $mobileFiltersOpen = false;

    /**
     * ISO-8601 timestamp of the last successful Graph sync (manual or poll).
     */
    public ?string $lastSyncedAt = null;

    /**
     * Last Graph sync failure message (kept until the next success).
     */
    public ?string $lastSyncError = null;

    /**
     * Transient toast for sync failures (poll or manual). Cleared on dismiss/success.
     */
    public ?string $syncToast = null;

    /** Compact draft strip under the thread header. */
    public bool $orderPanelOpen = false;

    /** Message currently targeted by the “Add to order fields” menu. */
    public ?int $mappingMessageId = null;

    /** Active mapping field: phone|name|address|product|null */
    public ?string $mappingField = null;

    /** Product search when mapping a message to Products. */
    public string $mappingProductSearch = '';

    /**
     * @var list<array{id: int, name: string, price: float}>
     */
    public array $mappingProductSuggestions = [];

    public function mount(ChannelInboxPurgeService $purge, ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

        $purge->purgeOnInboxLoad();

        if ($this->selectedConversationId
            && ! ChannelConversation::query()->whereKey($this->selectedConversationId)->exists()) {
            $this->selectedConversationId = null;
            $this->mobileThreadOpen = false;
        }

        if ($this->selectedConversationId) {
            $this->mobileThreadOpen = true;
            $conversation = ChannelConversation::query()->find($this->selectedConversationId);
            if ($conversation) {
                $this->markConversationRead($conversation, $replies);
            }
        }

        if ($this->channel !== '' || $this->unread !== '' || $this->window !== '' || $this->linked !== '') {
            $this->mobileFiltersOpen = true;
        }
    }

    public function selectConversation(int $conversationId, ChannelReplyService $replies): void
    {
        $conversation = ChannelConversation::query()->findOrFail($conversationId);
        $this->selectedConversationId = $conversation->id;
        $this->mobileThreadOpen = true;
        $this->resetComposer();
        $this->resetOrderMapping();
        $this->error = null;
        $this->statusMessage = null;
        $this->markConversationRead($conversation, $replies);
    }

    /**
     * Keep the mobile thread pane in sync when the browser Back/Forward
     * restores or clears ?conversation= (#[Url(history: true)]).
     * Also marks website read + Messenger seen when a conversation opens via URL history.
     */
    public function updatedSelectedConversationId(?int $conversationId): void
    {
        if ($conversationId === null) {
            $this->mobileThreadOpen = false;
            $this->resetComposer();
            $this->resetOrderMapping();
            $this->error = null;
            $this->statusMessage = null;

            return;
        }

        $conversation = ChannelConversation::query()->find($conversationId);

        if (! $conversation) {
            $this->selectedConversationId = null;
            $this->mobileThreadOpen = false;

            return;
        }

        $this->mobileThreadOpen = true;
        $this->resetComposer();
        $this->resetOrderMapping();
        $this->error = null;
        $this->statusMessage = null;
        $this->markConversationRead($conversation, app(ChannelReplyService::class));
    }

    public function closeMobileThread(): void
    {
        if ($this->selectedConversationId === null) {
            $this->mobileThreadOpen = false;
            $this->resetComposer();
            $this->error = null;
            $this->statusMessage = null;

            return;
        }

        // Livewire tests have no real history stack — clear synchronously.
        if (app()->runningUnitTests()) {
            $this->clearConversationFromUrl();

            return;
        }

        // Pop the history entry created when the thread was opened (history: true).
        // Livewire restores selectedConversationId from the previous URL; if back is
        // a no-op (direct deep link), fall back to clearing the query param.
        $this->js(<<<'JS'
            (() => {
                const before = window.location.href;
                window.history.back();
                setTimeout(() => {
                    if (window.location.href === before) {
                        $wire.clearConversationFromUrl();
                    }
                }, 50);
            })();
        JS);
    }

    public function clearConversationFromUrl(): void
    {
        $this->selectedConversationId = null;
        $this->mobileThreadOpen = false;
        $this->resetComposer();
        $this->resetOrderMapping();
        $this->error = null;
        $this->statusMessage = null;
    }

    public function toggleMobileFilters(): void
    {
        $this->mobileFiltersOpen = ! $this->mobileFiltersOpen;
    }

    public function toggleOrderPanel(ChannelOrderDraftService $drafts): void
    {
        AdminAccess::ensureStaffAdmin();

        if (! $this->selectedConversationId) {
            return;
        }

        $this->orderPanelOpen = ! $this->orderPanelOpen;

        if ($this->orderPanelOpen) {
            $conversation = ChannelConversation::query()->find($this->selectedConversationId);
            if ($conversation) {
                $drafts->ensureDraftForConversation($conversation, auth()->id());
            }
        }
    }

    public function openMessageMapMenu(int $messageId): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        $exists = ChannelMessage::query()
            ->where('channel_conversation_id', $this->selectedConversationId)
            ->whereKey($messageId)
            ->exists();

        if (! $exists) {
            return;
        }

        $this->mappingMessageId = $messageId;
        $this->mappingField = null;
        $this->mappingProductSearch = '';
        $this->mappingProductSuggestions = [];
        $this->error = null;
    }

    public function closeMessageMapMenu(): void
    {
        $this->mappingMessageId = null;
        $this->mappingField = null;
        $this->mappingProductSearch = '';
        $this->mappingProductSuggestions = [];
    }

    public function beginMapField(string $field, ChannelMessageOrderMapper $mapper): void
    {
        AdminAccess::ensureStaffAdmin();

        if (! $this->selectedConversationId || ! $this->mappingMessageId) {
            return;
        }

        try {
            $field = $mapper->normalizeField($field);
        } catch (InvalidArgumentException) {
            $this->error = 'Unknown order field.';

            return;
        }

        $message = ChannelMessage::query()
            ->where('channel_conversation_id', $this->selectedConversationId)
            ->whereKey($this->mappingMessageId)
            ->first();

        if (! $message) {
            $this->error = 'Message not found.';

            return;
        }

        $this->mappingField = $field;
        $suggestion = $mapper->suggest($message, $field);
        $this->mappingProductSuggestions = $suggestion['products'];
        $this->mappingProductSearch = (string) ($suggestion['value'] ?? '');

        if ($field !== ChannelMessageOrderMapper::FIELD_PRODUCT) {
            $this->applyMapField($field);
        }
    }

    public function applyMapField(string $field, ?int $productId = null): void
    {
        AdminAccess::ensureStaffAdmin();

        $drafts = app(ChannelOrderDraftService::class);
        $mapper = app(ChannelMessageOrderMapper::class);

        if (! $this->selectedConversationId || ! $this->mappingMessageId) {
            return;
        }

        $conversation = ChannelConversation::query()->find($this->selectedConversationId);
        $message = ChannelMessage::query()
            ->where('channel_conversation_id', $this->selectedConversationId)
            ->whereKey($this->mappingMessageId)
            ->first();

        if (! $conversation || ! $message) {
            $this->error = 'Conversation or message not found.';

            return;
        }

        try {
            $field = $mapper->normalizeField($field);
            $drafts->applyMessageToField($conversation, $message, $field, $productId, auth()->id());
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->orderPanelOpen = true;
        $this->statusMessage = 'Added to order '.$field.'.';
        $this->error = null;
        $this->closeMessageMapMenu();
    }

    public function updatedMappingProductSearch(): void
    {
        $term = trim($this->mappingProductSearch);
        if (mb_strlen($term) < 2) {
            $this->mappingProductSuggestions = [];

            return;
        }

        $this->mappingProductSuggestions = Product::query()
            ->searchTerm($term)
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'price'])
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'price' => (float) $product->price,
            ])
            ->all();
    }

    public function insertQuickReply(int $index): void
    {
        $replies = config('channels.inbox.quick_replies', []);
        $reply = $replies[$index] ?? null;
        if (! is_array($reply) || ! isset($reply['body'])) {
            return;
        }

        $body = trim((string) $reply['body']);
        if ($body === '') {
            return;
        }

        $this->replyText = $this->replyText === ''
            ? $body
            : rtrim($this->replyText)."\n".$body;
        $this->error = null;
    }

    public function setReplyTo(int $messageId): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        $target = ChannelMessage::query()
            ->where('channel_conversation_id', $this->selectedConversationId)
            ->whereKey($messageId)
            ->first();

        if (! $target) {
            return;
        }

        $this->replyToMessageId = $target->id;
        $this->error = null;
    }

    public function clearReplyTo(): void
    {
        $this->replyToMessageId = null;
    }

    public function clearReplyImage(): void
    {
        $this->replyImage = null;
    }

    public function sendReply(ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        if (! $this->selectedConversationId) {
            return;
        }

        $conversation = ChannelConversation::query()->find($this->selectedConversationId);

        if (! $conversation) {
            $this->error = 'Conversation not found.';

            return;
        }

        $this->validate([
            'replyText' => ['nullable', 'string', 'max:2000'],
            'replyImage' => ['nullable', 'image', 'max:5120'],
            'replyToMessageId' => ['nullable', 'integer'],
        ]);

        $replyTo = null;
        if ($this->replyToMessageId) {
            $replyTo = ChannelMessage::query()
                ->where('channel_conversation_id', $conversation->id)
                ->whereKey($this->replyToMessageId)
                ->first();

            if (! $replyTo) {
                $this->error = 'The message you are replying to was not found.';

                return;
            }
        }

        if ($this->replyImage) {
            $result = $replies->sendImage(
                $conversation,
                $this->replyImage,
                $this->replyText,
                false,
                $replyTo,
            );
        } else {
            $result = $replies->sendText(
                $conversation,
                $this->replyText,
                false,
                $replyTo,
            );
        }

        if (! $result['ok']) {
            $this->error = $result['error'] ?? 'Failed to send reply.';

            return;
        }

        $this->markConversationRead($conversation, $replies);
        $this->resetComposer();
        $this->statusMessage = 'Reply sent.';
    }

    public function syncFromFacebook(MessengerConversationSyncService $sync): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        $result = $sync->sync();
        $this->recordSyncResult($result, announceSuccess: true);

        if ($result['ok']) {
            $this->refreshOpenThreadAfterPoll();
            $this->deferMessengerSeenForOpenThread();
        }
    }

    /**
     * Background Graph sync while the Inbox tab is open (wire:poll.visible).
     * Quiet on success so the UI is not spammed every poll.
     */
    public function pollSyncFromFacebook(MessengerConversationSyncService $sync): void
    {
        AdminAccess::ensureStaffAdmin();

        $result = $sync->sync();
        $this->recordSyncResult($result, announceSuccess: false);

        // Local read-state only before render. Defer Graph mark_seen so a slow
        // Facebook call cannot delay Livewire morphing newly synced messages.
        $this->refreshOpenThreadAfterPoll();
        $this->deferMessengerSeenForOpenThread();
    }

    public function dismissSyncToast(): void
    {
        $this->syncToast = null;
    }

    public function clearFilters(): void
    {
        $this->channel = '';
        $this->unread = '';
        $this->window = '';
        $this->linked = '';
    }

    /**
     * Lightweight refresh for the open thread.
     * Retries Messenger mark_seen until Graph catches up to latest inbound.
     */
    public function refreshInbox(ChannelReplyService $replies): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        $conversation = ChannelConversation::query()->find($this->selectedConversationId);

        if (! $conversation) {
            return;
        }

        if ($conversation->isUnread()) {
            $conversation->markRead(auth()->id());
        }

        if ($conversation->needsMessengerSeenSync()) {
            $replies->markSeen($conversation);
        }
    }

    /**
     * After Graph sync, refresh the open thread without waiting on mark_seen.
     * Poll requests must stay fast so Livewire can morph new messages into the UI.
     */
    private function refreshOpenThreadAfterPoll(): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        $conversation = ChannelConversation::query()->find($this->selectedConversationId);

        if (! $conversation) {
            return;
        }

        if ($conversation->isUnread()) {
            $conversation->markRead(auth()->id());
        }
    }

    private function deferMessengerSeenForOpenThread(): void
    {
        $conversationId = $this->selectedConversationId;

        if (! $conversationId) {
            return;
        }

        defer(function () use ($conversationId): void {
            $conversation = ChannelConversation::query()->find($conversationId);
            if ($conversation?->needsMessengerSeenSync()) {
                app(ChannelReplyService::class)->markSeen($conversation);
            }
        });
    }

    /**
     * @param  array{ok: bool, message: string, conversations?: int, messages?: int, graph_threads?: int}  $result
     */
    private function recordSyncResult(array $result, bool $announceSuccess): void
    {
        if ($result['ok']) {
            $this->lastSyncedAt = now()->toIso8601String();
            $this->lastSyncError = null;
            $this->syncToast = null;

            if ($announceSuccess) {
                $this->statusMessage = $result['message'];
            }

            return;
        }

        $this->lastSyncError = $result['message'];
        $this->syncToast = $result['message'];

        if ($announceSuccess) {
            $this->error = $result['message'];
        }
    }

    private function markConversationRead(ChannelConversation $conversation, ChannelReplyService $replies): void
    {
        // Website unread first so the list updates even if Graph mark_seen is slow/fails.
        $conversation->markRead(auth()->id());
        $replies->markSeen($conversation);
    }

    private function resetComposer(): void
    {
        $this->replyText = '';
        $this->replyToMessageId = null;
        $this->replyImage = null;
    }

    private function resetOrderMapping(): void
    {
        $this->orderPanelOpen = false;
        $this->mappingMessageId = null;
        $this->mappingField = null;
        $this->mappingProductSearch = '';
        $this->mappingProductSuggestions = [];
    }

    public function render(ChannelInboxDiagnostics $diagnostics)
    {
        $query = ChannelConversation::query()
            ->with([
                'draftOrder:id,order_number,status,name,phone,address,total',
                'latestMessage',
            ])
            ->orderByRaw('COALESCE(last_inbound_at, last_outbound_at, created_at) desc');

        if ($this->channel !== '') {
            $query->where('channel', $this->channel);
        }

        if ($this->unread === '1') {
            $query->where(function ($q) {
                $q->whereNull('last_read_at')
                    ->orWhereColumn('last_inbound_at', '>', 'last_read_at');
            });
        }

        if ($this->window === '1') {
            $query->whereNotNull('last_inbound_at')
                ->where('last_inbound_at', '>', now()->subHours(24));
        }

        if ($this->linked === '1') {
            $query->whereNotNull('draft_order_id');
        }

        $conversations = $query->limit(100)->get();

        // Desktop convenience: preview the first thread without writing ?conversation= into the URL.
        // URL selection is only set by explicit open / deep link, and cleared by the mobile back button.
        $displayConversationId = $this->selectedConversationId;
        if ($displayConversationId === null && $conversations->isNotEmpty() && ! $this->mobileThreadOpen) {
            $displayConversationId = (int) $conversations->first()->id;
        }

        // If the selected id is outside the filtered list, still load it for the thread,
        // but never shrink the left list to that single conversation.
        $selectedConversation = $displayConversationId
            ? ChannelConversation::query()
                ->with([
                    'draftOrder.items',
                    'messages' => fn ($q) => $q->with('replyTo')->orderBy('sent_at')->orderBy('id'),
                ])
                ->find($displayConversationId)
            : null;

        $replyToMessage = null;
        if ($selectedConversation && $this->replyToMessageId) {
            $replyToMessage = $selectedConversation->messages->firstWhere('id', $this->replyToMessageId);
        }

        $quickReplies = collect(config('channels.inbox.quick_replies', []))
            ->filter(fn ($row) => is_array($row) && filled($row['label'] ?? null) && filled($row['body'] ?? null))
            ->values()
            ->all();

        return view('livewire.admin.admin-inbox', [
            'conversations' => $conversations,
            'selectedConversation' => $selectedConversation,
            'replyToMessage' => $replyToMessage,
            'quickReplies' => $quickReplies,
            'diagnostics' => $diagnostics->forInbox([
                'channel' => $this->channel,
                'unread' => $this->unread,
                'window' => $this->window,
                'linked' => $this->linked,
            ]),
        ]);
    }
}
