<?php

namespace App\Livewire\Admin;

use App\Models\Category;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Admin\ProductImageHashService;
use App\Services\Admin\ProductPricedImageService;
use App\Services\Channels\ChannelInboxDiagnostics;
use App\Services\Channels\ChannelInboxPurgeService;
use App\Services\Channels\ChannelMessageOrderMapper;
use App\Services\Channels\ChannelOrderDraftService;
use App\Services\Channels\ChannelReplyService;
use App\Services\Channels\InboxQuickReplyService;
use App\Services\Channels\MessengerConversationSyncService;
use App\Services\Facebook\FacebookPageTokenService;
use App\Support\AdminAccess;
use App\Support\Fileinfo;
use App\Support\StorefrontAssets;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

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
     * @var list<array{id: int, name: string, price: float, sku?: ?string, stock_quantity?: int, image_url?: ?string}>
     */
    public array $mappingProductSuggestions = [];

    /** Cropped JPEG uploaded from the inbox product-match cropper. */
    public $mappingCroppedImage = null;

    /**
     * @var list<array{product_id: int, name: string, sku: ?string, price: float, stock_quantity: int, image_url: ?string, match_percent: float, distance: int}>
     */
    public array $mappingImageMatches = [];

    public ?string $mappingImageMatchError = null;

    /** Inbound image message currently open in the edit-and-send modal. */
    public ?int $imageEditMessageId = null;

    /** Edited JPEG uploaded from the inbox image editor before send. */
    public $editedReplyImage = null;

    /** Inbound image message targeted by the priced-product send modal. */
    public ?int $pricedSendMessageId = null;

    public string $pricedSendSearch = '';

    public string $pricedSendCategory = '';

    public string $pricedSendPriceMin = '';

    public string $pricedSendPriceMax = '';

    /**
     * @var list<array{id: int, name: string, price: float, sku: ?string, category: ?string, image_url: ?string, priced_image_url: ?string, has_priced_image: bool}>
     */
    public array $pricedSendResults = [];

    /** When false, the open thread only shows recent messages (see thread_lookback_hours). */
    public bool $threadHistoryExpanded = false;

    /** Search message bodies in the open thread (searches full history). */
    public string $threadMessageSearch = '';

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
        $this->resetImageTools();
        $this->resetThreadHistory();
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
            $this->resetImageTools();
            $this->resetThreadHistory();
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
        $this->resetImageTools();
        $this->resetThreadHistory();
        $this->error = null;
        $this->statusMessage = null;
        $this->markConversationRead($conversation, app(ChannelReplyService::class));
    }

    public function closeMobileThread(): void
    {
        // Always return to the conversation list. Do not use history.back() —
        // the stack may hold a previous thread, filters, or a page outside Inbox.
        // Browser Back still works via #[Url(history: true)] + updatedSelectedConversationId.
        $this->clearConversationFromUrl();
    }

    public function clearConversationFromUrl(): void
    {
        $this->selectedConversationId = null;
        $this->mobileThreadOpen = false;
        $this->resetComposer();
        $this->resetOrderMapping();
        $this->resetImageTools();
        $this->resetThreadHistory();
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
        $this->clearMappingImageMatchState();
        $this->error = null;
    }

    public function closeMessageMapMenu(): void
    {
        $this->mappingMessageId = null;
        $this->mappingField = null;
        $this->mappingProductSearch = '';
        $this->mappingProductSuggestions = [];
        $this->clearMappingImageMatchState();
    }

    public function beginMapProductFromMessage(int $messageId, ChannelMessageOrderMapper $mapper): void
    {
        $this->openMessageMapMenu($messageId);
        $this->beginMapField(ChannelMessageOrderMapper::FIELD_PRODUCT, $mapper);
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
        $this->clearMappingImageMatchState();
        $suggestion = $mapper->suggest($message, $field);

        if ($field === ChannelMessageOrderMapper::FIELD_PRODUCT) {
            $this->mappingProductSearch = (string) ($suggestion['value'] ?? '');
            $this->mappingProductSuggestions = $this->enrichProductSuggestions(
                array_map(fn (array $row) => [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'price' => (float) $row['price'],
                ], $suggestion['products']),
            );

            if ($this->mappingProductSearch !== '' && $this->mappingProductSuggestions === []) {
                $this->updatedMappingProductSearch();
            }

            return;
        }

        $this->mappingProductSuggestions = $suggestion['products'];
        $this->mappingProductSearch = (string) ($suggestion['value'] ?? '');
        $this->applyMapField($field);
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
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
            ->searchTerm($term)
            ->orderBy('name')
            ->limit(12)
            ->get()
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'sku' => $product->sku,
                'price' => (float) $product->price,
                'stock_quantity' => (int) $product->stock_quantity,
                'image_url' => StorefrontAssets::url($product->primaryImagePath()),
            ])
            ->all();
    }

    public function matchProductFromCroppedImage(ProductImageHashService $hasher): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->mappingImageMatchError = null;
        $this->mappingImageMatches = [];

        $this->validate([
            'mappingCroppedImage' => Fileinfo::storedImageRules(10240),
        ]);

        try {
            $hash = $hasher->hashUploadedFile($this->mappingCroppedImage);
            $matches = $hasher->findTopMatches(
                $hash,
                ProductImageHashService::TOP_MATCHES,
                ProductImageHashService::MIN_MATCH_PERCENT,
            );

            // Always list matches so staff can confirm before adding; do not auto-apply/close.
            $this->mappingImageMatches = $matches;
            if ($matches === []) {
                $this->mappingImageMatchError = 'No catalog match at 80%+. Try a tighter crop or search by name.';
            }
        } catch (Throwable $e) {
            $this->mappingImageMatchError = $e->getMessage();
        } finally {
            $this->mappingCroppedImage = null;
        }
    }

    public function selectMappingImageMatch(int $productId): void
    {
        $this->applyMapField(ChannelMessageOrderMapper::FIELD_PRODUCT, $productId);
    }

    public function insertQuickReply(int $index, InboxQuickReplyService $quickReplies): void
    {
        $replies = $quickReplies->all();
        $reply = $replies[$index] ?? null;
        if (! is_array($reply) || ! isset($reply['body'])) {
            return;
        }

        $body = trim(str_replace(["\r\n", "\r"], "\n", (string) $reply['body']));
        if ($body === '') {
            return;
        }

        $this->replyText = $this->replyText === ''
            ? $body
            : rtrim(str_replace(["\r\n", "\r"], "\n", $this->replyText))."\n".$body;
        $this->error = null;
    }

    public function loadOlderThreadHistory(): void
    {
        $this->threadHistoryExpanded = true;
    }

    public function clearThreadMessageSearch(): void
    {
        $this->threadMessageSearch = '';
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

    public function openImageEdit(int $messageId): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->error = null;
        $this->statusMessage = null;

        $message = $this->inboundImageMessage($messageId);
        if (! $message) {
            $this->error = 'Image message not found.';

            return;
        }

        $this->ensureConversationSelected((int) $message->channel_conversation_id);
        $this->closePricedImageSend();
        $this->editedReplyImage = null;
        $this->imageEditMessageId = $message->id;
    }

    public function closeImageEdit(): void
    {
        $this->imageEditMessageId = null;
        $this->editedReplyImage = null;
    }

    public function sendEditedImageReply(ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        if (! $this->imageEditMessageId) {
            return;
        }

        $replyTo = $this->inboundImageMessage($this->imageEditMessageId);
        if (! $replyTo) {
            $this->error = 'Image message not found.';
            $this->closeImageEdit();

            return;
        }

        $this->ensureConversationSelected((int) $replyTo->channel_conversation_id);

        $conversation = ChannelConversation::query()->find($this->selectedConversationId);
        if (! $conversation) {
            $this->error = 'Conversation not found.';

            return;
        }

        $this->validate([
            'editedReplyImage' => ['required', 'image', 'max:5120'],
        ]);

        $result = $replies->sendImage(
            $conversation,
            $this->editedReplyImage,
            '',
            false,
            $replyTo,
        );

        if (! $result['ok']) {
            $this->error = $result['error'] ?? 'Failed to send edited image.';

            return;
        }

        $this->markConversationRead($conversation, $replies);
        $this->closeImageEdit();
        $this->statusMessage = 'Edited image sent.';
    }

    public function openPricedImageSend(int $messageId): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->error = null;
        $this->statusMessage = null;

        $message = $this->inboundImageMessage($messageId);
        if (! $message) {
            $this->error = 'Image message not found.';

            return;
        }

        $this->ensureConversationSelected((int) $message->channel_conversation_id);
        $this->closeImageEdit();
        $this->pricedSendMessageId = $message->id;
        $this->pricedSendSearch = '';
        $this->pricedSendCategory = '';
        $this->pricedSendPriceMin = '';
        $this->pricedSendPriceMax = '';
        $this->refreshPricedSendResults();
    }

    public function closePricedImageSend(): void
    {
        $this->pricedSendMessageId = null;
        $this->pricedSendSearch = '';
        $this->pricedSendCategory = '';
        $this->pricedSendPriceMin = '';
        $this->pricedSendPriceMax = '';
        $this->pricedSendResults = [];
    }

    public function updatedPricedSendSearch(): void
    {
        $this->refreshPricedSendResults();
    }

    public function updatedPricedSendCategory(): void
    {
        $this->refreshPricedSendResults();
    }

    public function updatedPricedSendPriceMin(): void
    {
        $this->refreshPricedSendResults();
    }

    public function updatedPricedSendPriceMax(): void
    {
        $this->refreshPricedSendResults();
    }

    public function sendPricedProductImage(
        int $productId,
        ChannelReplyService $replies,
        ProductPricedImageService $pricedImages,
    ): void {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        if (! $this->pricedSendMessageId) {
            return;
        }

        $replyTo = $this->inboundImageMessage($this->pricedSendMessageId);
        if (! $replyTo) {
            $this->error = 'Image message not found.';
            $this->closePricedImageSend();

            return;
        }

        $this->ensureConversationSelected((int) $replyTo->channel_conversation_id);

        $conversation = ChannelConversation::query()->find($this->selectedConversationId);
        if (! $conversation) {
            $this->error = 'Conversation not found.';

            return;
        }

        $product = Product::query()->find($productId);
        if (! $product) {
            $this->error = 'Product not found.';

            return;
        }

        try {
            $pricedPath = is_string($product->priced_image_path) ? trim($product->priced_image_path) : '';
            if ($pricedPath === '' || ! is_file(public_path(ltrim($pricedPath, '/')))) {
                $pricedPath = $pricedImages->generate($product);
                $product->refresh();
            }

            $upload = $this->uploadedFileFromPublicPath($pricedPath);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $result = $replies->sendImage(
            $conversation,
            $upload,
            '',
            false,
            $replyTo,
        );

        if (! $result['ok']) {
            $this->error = $result['error'] ?? 'Failed to send priced image.';

            return;
        }

        $this->markConversationRead($conversation, $replies);
        $this->closePricedImageSend();
        $this->statusMessage = 'Priced image sent.';
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

    /**
     * Echo / Livewire listener: re-render list + open thread from DB (no Graph).
     * When the event targets the open conversation, also mark website read / defer seen.
     */
    public function refreshFromRealtime(?int $conversationId = null): void
    {
        AdminAccess::ensureStaffAdmin();

        if ($conversationId && $this->selectedConversationId === $conversationId) {
            $this->refreshOpenThreadAfterPoll();
            $this->deferMessengerSeenForOpenThread();
        }
    }

    public function realtimeEnabled(): bool
    {
        return (bool) config('channels.inbox.realtime_enabled');
    }

    public function graphPollSeconds(): int
    {
        if ($this->realtimeEnabled()) {
            return max(10, (int) config('channels.inbox.graph_poll_seconds_realtime', 60));
        }

        return max(5, (int) config('channels.inbox.graph_poll_seconds', 10));
    }

    public function clearFilters(): void
    {
        $this->channel = '';
        $this->unread = '';
        $this->window = '';
        $this->linked = '';
    }

    /**
     * Retry Messenger mark_seen for the open thread (after Graph sync morphs).
     */
    public function retryMessengerSeen(ChannelReplyService $replies): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        $conversation = ChannelConversation::query()->find($this->selectedConversationId);

        if ($conversation?->needsMessengerSeenSync()) {
            $replies->markSeen($conversation);
        }
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

        $this->retryMessengerSeen($replies);
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
        if (! $this->selectedConversationId) {
            return;
        }

        $conversation = ChannelConversation::query()->find($this->selectedConversationId);

        if (! $conversation?->needsMessengerSeenSync()) {
            return;
        }

        // Prefer a follow-up Livewire request after morph (does not block new messages).
        // In HTTP tests / non-browser contexts, run mark_seen immediately.
        if (app()->runningUnitTests()) {
            $this->retryMessengerSeen(app(ChannelReplyService::class));

            return;
        }

        $this->js('queueMicrotask(() => $wire.retryMessengerSeen())');
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

        if ($this->isFacebookTokenSyncError($result['message'])) {
            app(FacebookPageTokenService::class)->invalidateStatusCache();
            $this->dispatch('facebook-token-recheck');
        }

        if ($announceSuccess) {
            $this->error = $result['message'];
        }
    }

    private function isFacebookTokenSyncError(string $message): bool
    {
        $haystack = mb_strtolower($message);

        return str_contains($haystack, 'access token')
            || str_contains($haystack, 'oauth')
            || str_contains($haystack, 'session has expired')
            || str_contains($haystack, 'error validating')
            || str_contains($haystack, 'invalid oauth')
            || str_contains($haystack, 'page id is not configured');
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
        $this->clearMappingImageMatchState();
    }

    private function resetImageTools(): void
    {
        $this->closeImageEdit();
        $this->closePricedImageSend();
    }

    private function clearMappingImageMatchState(): void
    {
        $this->mappingCroppedImage = null;
        $this->mappingImageMatches = [];
        $this->mappingImageMatchError = null;
    }

    private function inboundImageMessage(int $messageId): ?ChannelMessage
    {
        if ($messageId <= 0) {
            return null;
        }

        $message = ChannelMessage::query()->whereKey($messageId)->first();

        if (! $message
            || $message->direction !== ChannelMessage::DIRECTION_INBOUND
            || ! $message->isImageAttachment()) {
            return null;
        }

        // When a conversation is explicitly selected, keep tools scoped to it.
        if ($this->selectedConversationId
            && (int) $message->channel_conversation_id !== (int) $this->selectedConversationId) {
            return null;
        }

        return $message;
    }

    /**
     * Desktop preview can show a thread without writing ?conversation= into the URL.
     * Image tools still need a selected conversation id for send/reply actions.
     */
    private function ensureConversationSelected(int $conversationId): void
    {
        if ($conversationId <= 0) {
            return;
        }

        if ($this->selectedConversationId === $conversationId) {
            return;
        }

        $this->selectedConversationId = $conversationId;
        $this->mobileThreadOpen = true;
    }

    private function refreshPricedSendResults(): void
    {
        if (! $this->pricedSendMessageId) {
            $this->pricedSendResults = [];

            return;
        }

        $priceMin = $this->normalizedPriceBound($this->pricedSendPriceMin);
        $priceMax = $this->normalizedPriceBound($this->pricedSendPriceMax);

        if ($priceMin !== null && $priceMax !== null && $priceMin > $priceMax) {
            [$priceMin, $priceMax] = [$priceMax, $priceMin];
        }

        $term = trim($this->pricedSendSearch);

        $products = Product::query()
            ->with([
                'category:id,name',
                'images' => fn ($q) => $q->orderBy('sort_order')->limit(1),
            ])
            ->when($term !== '', fn ($q) => $q->searchTerm($term, includePrice: false))
            ->when($priceMin !== null, fn ($q) => $q->where('price', '>=', $priceMin))
            ->when($priceMax !== null, fn ($q) => $q->where('price', '<=', $priceMax))
            ->when($this->pricedSendCategory !== '', fn ($q) => $q->where('category_id', (int) $this->pricedSendCategory))
            ->orderBy('display_order')
            ->orderByDesc('id')
            ->limit(24)
            ->get();

        $this->pricedSendResults = $products->map(function (Product $product) {
            $pricedPath = is_string($product->priced_image_path) ? trim($product->priced_image_path) : '';
            $hasPriced = $pricedPath !== '';
            $previewPath = $hasPriced ? $pricedPath : $product->primaryImagePath();

            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->price,
                'sku' => $product->sku,
                'category' => $product->category?->name,
                'image_url' => StorefrontAssets::url($previewPath),
                'priced_image_url' => $hasPriced ? StorefrontAssets::url($pricedPath) : null,
                'has_priced_image' => $hasPriced,
            ];
        })->all();
    }

    private function normalizedPriceBound(string $value): ?float
    {
        $digits = preg_replace('/[^\d.]/', '', trim($value)) ?? '';

        if ($digits === '' || ! is_numeric($digits)) {
            return null;
        }

        return max(0, (float) $digits);
    }

    private function uploadedFileFromPublicPath(string $relativePath): UploadedFile
    {
        $absolute = public_path(ltrim($relativePath, '/'));

        if (! is_file($absolute) || ! is_readable($absolute)) {
            throw new RuntimeException('Priced image file is missing on disk.');
        }

        $mime = mime_content_type($absolute) ?: 'image/jpeg';
        if (! str_starts_with($mime, 'image/')) {
            throw new RuntimeException('Priced image file is not a valid image.');
        }

        return new UploadedFile(
            $absolute,
            basename($absolute),
            $mime,
            null,
            true,
        );
    }

    /**
     * @param  list<array{id: int, name: string, price: float}>  $rows
     * @return list<array{id: int, name: string, price: float, sku: ?string, stock_quantity: int, image_url: ?string}>
     */
    private function enrichProductSuggestions(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map(fn (array $row) => (int) $row['id'], $rows)));
        $products = Product::query()
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')->limit(1)])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $enriched = [];
        foreach ($rows as $row) {
            $product = $products->get((int) $row['id']);
            $enriched[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'price' => (float) $row['price'],
                'sku' => $product?->sku,
                'stock_quantity' => (int) ($product?->stock_quantity ?? 0),
                'image_url' => $product ? StorefrontAssets::url($product->primaryImagePath()) : null,
            ];
        }

        return $enriched;
    }

    private function resetThreadHistory(): void
    {
        $this->threadHistoryExpanded = false;
        $this->threadMessageSearch = '';
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

        $threadSearch = trim($this->threadMessageSearch);
        $lookbackHours = max(1, (int) config('channels.inbox.thread_lookback_hours', 24));
        $threadSince = now()->subHours($lookbackHours);
        $hasOlderMessages = false;

        // If the selected id is outside the filtered list, still load it for the thread,
        // but never shrink the left list to that single conversation.
        $selectedConversation = $displayConversationId
            ? ChannelConversation::query()
                ->with([
                    'draftOrder.items',
                    'messages' => function ($q) use ($threadSearch, $threadSince) {
                        $q->with('replyTo')->orderBy('sent_at')->orderBy('id');

                        if ($threadSearch !== '') {
                            $q->where('body', 'like', '%'.$threadSearch.'%');

                            return;
                        }

                        if (! $this->threadHistoryExpanded) {
                            $q->where(function ($window) use ($threadSince) {
                                $window->where('sent_at', '>=', $threadSince)
                                    ->orWhereNull('sent_at');
                            });
                        }
                    },
                ])
                ->find($displayConversationId)
            : null;

        if ($selectedConversation && $threadSearch === '' && ! $this->threadHistoryExpanded) {
            $hasOlderMessages = ChannelMessage::query()
                ->where('channel_conversation_id', $selectedConversation->id)
                ->whereNotNull('sent_at')
                ->where('sent_at', '<', $threadSince)
                ->exists();
        }

        $replyToMessage = null;
        if ($selectedConversation && $this->replyToMessageId) {
            $replyToMessage = $selectedConversation->messages->firstWhere('id', $this->replyToMessageId)
                ?? ChannelMessage::query()
                    ->where('channel_conversation_id', $selectedConversation->id)
                    ->whereKey($this->replyToMessageId)
                    ->first();
        }

        $quickReplies = app(InboxQuickReplyService::class)->all();

        $mappingMessage = null;
        if ($selectedConversation && $this->mappingMessageId) {
            $mappingMessage = $selectedConversation->messages->firstWhere('id', $this->mappingMessageId)
                ?? ChannelMessage::query()
                    ->where('channel_conversation_id', $selectedConversation->id)
                    ->whereKey($this->mappingMessageId)
                    ->first();
        }

        $imageEditMessage = null;
        if ($selectedConversation && $this->imageEditMessageId) {
            $imageEditMessage = $selectedConversation->messages->firstWhere('id', $this->imageEditMessageId)
                ?? ChannelMessage::query()
                    ->where('channel_conversation_id', $selectedConversation->id)
                    ->whereKey($this->imageEditMessageId)
                    ->first();
        }

        $pricedSendMessage = null;
        if ($selectedConversation && $this->pricedSendMessageId) {
            $pricedSendMessage = $selectedConversation->messages->firstWhere('id', $this->pricedSendMessageId)
                ?? ChannelMessage::query()
                    ->where('channel_conversation_id', $selectedConversation->id)
                    ->whereKey($this->pricedSendMessageId)
                    ->first();
        }

        return view('livewire.admin.admin-inbox', [
            'conversations' => $conversations,
            'selectedConversation' => $selectedConversation,
            'replyToMessage' => $replyToMessage,
            'mappingMessage' => $mappingMessage,
            'imageEditMessage' => $imageEditMessage,
            'pricedSendMessage' => $pricedSendMessage,
            'pricedSendCategories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'quickReplies' => $quickReplies,
            'hasOlderMessages' => $hasOlderMessages,
            'threadLookbackHours' => $lookbackHours,
            'realtimeEnabled' => $this->realtimeEnabled(),
            'graphPollSeconds' => $this->graphPollSeconds(),
            'diagnostics' => $diagnostics->forInbox([
                'channel' => $this->channel,
                'unread' => $this->unread,
                'window' => $this->window,
                'linked' => $this->linked,
            ]),
        ]);
    }
}
