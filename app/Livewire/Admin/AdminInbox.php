<?php

namespace App\Livewire\Admin;

use App\Models\Category;
use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Models\Product;
use App\Services\Admin\InboxPricedImageSender;
use App\Services\Admin\InboxSnapshotBuilder;
use App\Services\Channels\ChannelInboxDiagnostics;
use App\Services\Channels\ChannelInboxPurgeService;
use App\Services\Channels\ChannelMessageImageMatchService;
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
use Illuminate\Support\Facades\Log;
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

    /** Newest inbound photos per thread whose matches are tracked. */
    private const IMAGE_MATCH_THREAD_LIMIT = 60;

    private const IMAGE_MATCH_SUGGESTIONS = 3;

    /** @var array{left: float, top: float, width: float, height: float} */
    private const FULL_FRAME_REGION = ['left' => 0.0, 'top' => 0.0, 'width' => 1.0, 'height' => 1.0];

    #[Url]
    public string $channel = '';

    #[Url]
    public string $unread = '';

    #[Url]
    public string $window = '';

    #[Url]
    public string $linked = '';

    #[Url(as: 'conversation', history: false)]
    public ?int $selectedConversationId = null;

    /** Sticky desktop preview so poll reorder does not swap the open thread. */
    public ?int $desktopPreviewConversationId = null;

    public string $replyText = '';

    public ?int $replyToMessageId = null;

    /**
     * Composer image attachments (multi-select).
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $replyImages = [];

    /**
     * Outbound reply staged for a background Graph send so the composer stays responsive.
     */
    public string $pendingReplyText = '';

    /**
     * @var array<int, TemporaryUploadedFile>
     */
    public array $pendingReplyImages = [];

    public ?int $pendingReplyToMessageId = null;

    public bool $outboundSending = false;

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

    /** Browser text selection captured when opening the map menu (optional). */
    public ?string $mappingSelectedText = null;

    /** Active mapping field: phone|name|address|product|null */
    public ?string $mappingField = null;

    /**
     * Product modal intent: tag a catalog product on an inbound image, or add to the order draft.
     *
     * @var 'tag'|'order'
     */
    public string $mappingMode = 'order';

    /** Product search when mapping a message to Products. */
    public string $mappingProductSearch = '';

    /**
     * @var list<array{id: int, name: string, price: float, sku?: ?string, stock_quantity?: int, image_url?: ?string}>
     */
    public array $mappingProductSuggestions = [];

    /** Cropped JPEG uploaded from the inbox product-match cropper. */
    public $mappingCroppedImage = null;

    /**
     * @var list<array{product_id: int, name: string, sku: ?string, price: float, stock_quantity: int, image_url: ?string, match_percent: float}>
     */
    public array $mappingImageMatches = [];

    public ?string $mappingImageMatchError = null;

    /**
     * Staff crop on the chat image (fractions of the original), learned on tag.
     *
     * @var array{left: float, top: float, width: float, height: float}|null
     */
    public ?array $mappingCropRegion = null;

    /** Inbound image message currently open in the edit-and-send modal. */
    public ?int $imageEditMessageId = null;

    /** Edited JPEG uploaded from the inbox image editor before send. */
    public $editedReplyImage = null;

    /** Inbound image message targeted by the priced-product send modal. */
    public ?int $pricedSendMessageId = null;

    /** Composer "+P" product picker (search + send images/link/price/priced image). */
    public bool $composerProductPickerOpen = false;

    public string $pricedSendSearch = '';

    public string $pricedSendCategory = '';

    public string $pricedSendPriceMin = '';

    public string $pricedSendPriceMax = '';

    /**
     * @var list<array{id: int, name: string, price: float, sku: ?string, category: ?string, image_url: ?string, priced_image_url: ?string, has_priced_image: bool}>
     */
    public array $pricedSendResults = [];

    /**
     * Product matches for inbound customer photos in the open thread, read from the
     * persisted per-photo result. "pending" photos are matched by the browser queue
     * (parallel POSTs to admin.inbox.image-match), never inside a Livewire request.
     *
     * @var array<string, array{
     *     status: 'pending'|'done',
     *     match_status?: ?string,
     *     match_url?: string,
     *     product_id?: int,
     *     name?: string,
     *     match_percent?: float,
     *     strategy?: string,
     *     suggestions?: list<array{product_id: int, name: string, image_url: ?string, match_percent: float}>
     * }>
     */
    public array $inboundImageMatchState = [];

    /**
     * Admin-facing problems that make photo matching slow or fail (index incomplete,
     * cache folder not writable), shown above the thread.
     *
     * @var list<array{message: string, action_url?: string, action_label?: string}>
     */
    public array $imageMatchNotices = [];

    /**
     * When this page first showed each photo attempt ("{messageId}:{attemptKey}") as pending.
     *
     * @var array<string, int>
     */
    public array $imageMatchPendingSince = [];

    /** When false, the open thread only shows recent messages (see thread_lookback_hours). */
    public bool $threadHistoryExpanded = false;

    /** Search message bodies in the open thread (searches full history). */
    public string $threadMessageSearch = '';

    /** Skip updatedSelectedConversationId when selectConversation already activated the thread. */
    private bool $suppressConversationUpdatedHook = false;

    public function mount(ChannelInboxPurgeService $purge, ChannelReplyService $replies, mixed $thread = null): void
    {
        AdminAccess::ensureStaffAdmin();

        $purge->purgeOnInboxLoad();

        $threadId = is_numeric($thread) ? (int) $thread : null;
        if ($threadId && ! $this->selectedConversationId) {
            $this->selectedConversationId = $threadId;
        }

        if ($this->selectedConversationId
            && ! ChannelConversation::query()->whereKey($this->selectedConversationId)->exists()) {
            $this->selectedConversationId = null;
            $this->mobileThreadOpen = false;
        }

        if ($this->selectedConversationId) {
            $this->mobileThreadOpen = true;
            $conversation = ChannelConversation::query()->find($this->selectedConversationId);
            if ($conversation) {
                $this->desktopPreviewConversationId = $conversation->id;
                $this->activateConversation($conversation, $replies);
            }
        }

        if ($this->channel !== '' || $this->unread !== '' || $this->window !== '' || $this->linked !== '') {
            $this->mobileFiltersOpen = true;
        }
    }

    public function selectConversation(int $conversationId, ChannelReplyService $replies): void
    {
        $conversation = ChannelConversation::query()->findOrFail($conversationId);

        $this->suppressConversationUpdatedHook = true;
        $this->selectedConversationId = $conversation->id;
        $this->desktopPreviewConversationId = $conversation->id;
        $this->suppressConversationUpdatedHook = false;

        $this->activateConversation($conversation, $replies);
    }

    /**
     * Keep the mobile thread pane in sync when the browser Back/Forward
     * restores or clears ?conversation= (URL is replaceState; list→thread
     * pushes one history entry from the conversation list click handler).
     * Also marks website read + Messenger seen when a conversation opens via URL history.
     */
    public function updatedSelectedConversationId(?int $conversationId): void
    {
        if ($this->suppressConversationUpdatedHook) {
            return;
        }

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

        $this->desktopPreviewConversationId = $conversation->id;
        $this->activateConversation($conversation, app(ChannelReplyService::class));
    }

    private function activateConversation(ChannelConversation $conversation, ChannelReplyService $replies): void
    {
        $this->mobileThreadOpen = true;
        $this->resetComposer();
        $this->resetOrderMapping();
        $this->resetImageTools();
        $this->resetThreadHistory();
        $this->error = null;
        $this->statusMessage = null;
        $this->markConversationRead($conversation, $replies);
        $this->queueInboundImageMatching();
    }

    public function closeMobileThread(): void
    {
        // Always return to the conversation list. Do not use history.back() —
        // the stack may hold a previous page outside Inbox. Mobile swipe-back is
        // handled in inbox-thread-history.js so it closes the thread instead of
        // restoring a previous ?conversation= URL.
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

    public function openMessageMapMenu(int $messageId, ?string $selectedText = null): void
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

        $selectedText = trim((string) $selectedText);

        $this->mappingMessageId = $messageId;
        $this->mappingSelectedText = $selectedText !== '' ? $selectedText : null;
        $this->mappingField = null;
        $this->mappingMode = 'order';
        $this->mappingProductSearch = '';
        $this->mappingProductSuggestions = [];
        $this->clearMappingImageMatchState();
        $this->error = null;
    }

    public function closeMessageMapMenu(): void
    {
        $this->mappingMessageId = null;
        $this->mappingSelectedText = null;
        $this->mappingField = null;
        $this->mappingMode = 'order';
        $this->mappingProductSearch = '';
        $this->mappingProductSuggestions = [];
        $this->clearMappingImageMatchState();
    }

    /**
     * Search icon on inbound photos: find and tag a catalog product on the message.
     */
    public function openTagProductOnImage(int $messageId, ChannelMessageOrderMapper $mapper): void
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
        $this->closePricedImageSend();
        $this->openMessageMapMenu($message->id);
        $this->mappingMode = 'tag';
        $this->beginMapField(ChannelMessageOrderMapper::FIELD_PRODUCT, $mapper);
    }

    /** @deprecated Use openTagProductOnImage for inbound photos. */
    public function beginMapProductFromMessage(int $messageId, ChannelMessageOrderMapper $mapper): void
    {
        $this->openTagProductOnImage($messageId, $mapper);
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
        $suggestion = $mapper->suggest($message, $field, $this->mappingSelectedText);

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

            if ($message->isImageAttachment()) {
                $this->loadStoredImageMatches($message);
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
            $drafts->applyMessageToField(
                $conversation,
                $message,
                $field,
                $productId,
                auth()->id(),
                $this->mappingSelectedText,
            );
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
            ->published()
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

    /**
     * Rotated crops are uploaded as pixels (the crop box no longer maps onto the original).
     */
    public function matchProductFromCroppedImage(ChannelMessageImageMatchService $matches): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->mappingImageMatchError = null;
        $this->mappingImageMatches = [];
        $this->mappingCropRegion = null;

        $this->validate([
            'mappingCroppedImage' => Fileinfo::storedImageRules(10240),
        ]);

        try {
            $bytes = (string) file_get_contents($this->mappingCroppedImage->getRealPath());
            $this->showCropMatches($matches->searchBinary($bytes, self::FULL_FRAME_REGION)['rows']);
        } catch (Throwable $e) {
            $this->mappingImageMatchError = $e->getMessage();
        } finally {
            $this->mappingCroppedImage = null;
        }
    }

    /**
     * Match the staff crop box on the original chat image: no upload, and the region
     * is learned when staff tag the result.
     *
     * @param  array<string, mixed>  $region  left/top/width/height fractions of the original image
     */
    public function matchProductFromCropRegion(array $region, ChannelMessageImageMatchService $matches): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->mappingImageMatchError = null;
        $this->mappingImageMatches = [];
        $this->mappingCropRegion = null;

        $fractions = $this->normalizeCropRegion($region);
        $message = $this->mappingMessageId ? $this->inboundImageMessage($this->mappingMessageId) : null;

        if ($fractions === null || $message === null) {
            $this->mappingImageMatchError = 'Select an area of the chat image first.';

            return;
        }

        try {
            $downloaded = $matches->downloadInboundImageBytes($message);
            if ($downloaded === null) {
                $this->mappingImageMatchError = 'Could not load the chat image.';

                return;
            }

            $this->mappingCropRegion = $fractions;
            $this->showCropMatches($matches->searchBinary($downloaded['bytes'], $fractions)['rows']);
        } catch (Throwable $e) {
            $this->mappingImageMatchError = $e->getMessage();
        }
    }

    public function selectMappingImageMatch(int $productId): void
    {
        $this->chooseMappingProduct($productId);
    }

    /**
     * Confirm a product from search or crop results (tag vs add-to-order depends on mappingMode).
     */
    public function chooseMappingProduct(int $productId): void
    {
        if ($this->mappingMode === 'tag') {
            $this->tagMatchedProduct($productId);

            return;
        }

        $this->applyMapField(ChannelMessageOrderMapper::FIELD_PRODUCT, $productId);
    }

    /**
     * Persist a catalog product tag on the inbound image message (no order change).
     */
    public function tagMatchedProduct(int $productId): void
    {
        AdminAccess::ensureStaffAdmin();

        if (! $this->selectedConversationId || ! $this->mappingMessageId) {
            return;
        }

        $message = ChannelMessage::query()
            ->where('channel_conversation_id', $this->selectedConversationId)
            ->whereKey($this->mappingMessageId)
            ->first();

        $product = Product::query()
            ->whereKey($productId)
            ->where('is_published', true)
            ->first();

        if (! $message || ! $product) {
            $this->error = 'Message or product not found.';

            return;
        }

        app(ChannelMessageImageMatchService::class)->tag($message, $product, auth()->user(), $this->mappingCropRegion);
        $this->syncInboundImageMatchState();

        $this->statusMessage = 'Tagged “'.$product->name.'” on this photo.';
        $this->error = null;
        $this->closeMessageMapMenu();
    }

    /**
     * One-click confirm of a suggested product under an inbound photo.
     */
    public function tagSuggestedProduct(int $messageId, int $productId): void
    {
        AdminAccess::ensureStaffAdmin();

        $message = $this->inboundImageMessage($messageId);
        $product = Product::query()->published()->whereKey($productId)->first();

        if (! $message || ! $product) {
            $this->error = 'Message or product not found.';

            return;
        }

        $this->ensureConversationSelected((int) $message->channel_conversation_id);
        app(ChannelMessageImageMatchService::class)->tag($message, $product, auth()->user());
        $this->syncInboundImageMatchState();

        $this->statusMessage = 'Tagged “'.$product->name.'” on this photo.';
        $this->error = null;
    }

    /**
     * "Not this": reject the attached product for this photo, then open the product picker.
     */
    public function rejectMatchedProduct(int $messageId, ChannelMessageOrderMapper $mapper): void
    {
        AdminAccess::ensureStaffAdmin();

        $message = $this->inboundImageMessage($messageId);
        if (! $message) {
            return;
        }

        app(ChannelMessageImageMatchService::class)->reject($message);
        $this->syncInboundImageMatchState();

        $this->openTagProductOnImage($messageId, $mapper);
    }

    /**
     * Staff "Retry" on a photo whose match failed or never finished.
     */
    public function retryInboundImageMatch(int $messageId): void
    {
        AdminAccess::ensureStaffAdmin();

        $message = $this->inboundImageMessage($messageId);
        if (! $message) {
            return;
        }

        app(ChannelMessageImageMatchService::class)->resetForRetry($message);
        $this->syncInboundImageMatchState();
    }

    /**
     * Add the tagged product from an inbound image to the conversation order draft.
     */
    public function addMatchedProductToOrder(int $messageId): void
    {
        AdminAccess::ensureStaffAdmin();

        $message = $this->inboundImageMessage($messageId);
        $productId = (int) ($message?->matched_product_id ?? 0);

        if (! $message || $productId <= 0) {
            $this->error = 'No product tagged on this image.';

            return;
        }

        $this->ensureConversationSelected((int) $message->channel_conversation_id);
        $this->mappingMessageId = $message->id;
        $this->mappingMode = 'order';
        $this->applyMapField(ChannelMessageOrderMapper::FIELD_PRODUCT, $productId);
    }

    /**
     * Clear the catalog product tag from an inbound image.
     */
    public function clearMatchedProduct(int $messageId): void
    {
        AdminAccess::ensureStaffAdmin();

        $message = $this->inboundImageMessage($messageId);
        if (! $message) {
            return;
        }

        app(ChannelMessageImageMatchService::class)->reject($message);
        $this->syncInboundImageMatchState();

        $this->statusMessage = 'Product tag cleared.';
        $this->error = null;
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

        $this->replyText = $body;
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

    public function clearReplyImages(): void
    {
        $this->replyImages = [];
    }

    public function removeReplyImage(int $index): void
    {
        if (! array_key_exists($index, $this->replyImages)) {
            return;
        }

        unset($this->replyImages[$index]);
        $this->replyImages = array_values($this->replyImages);
    }

    public function reportReplyImageUploadError(?string $message = null): void
    {
        $this->error = $message
            ?: 'Image upload failed. Use JPG, PNG, WEBP, or GIF (max 5MB each).';
        $this->statusMessage = null;
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
        $this->composerProductPickerOpen = false;
        $this->pricedSendMessageId = $message->id;
        $this->pricedSendSearch = '';
        $this->pricedSendCategory = '';
        $this->pricedSendPriceMin = '';
        $this->pricedSendPriceMax = '';
        $this->refreshPricedSendResults();
    }

    public function openComposerProductPicker(): void
    {
        AdminAccess::ensureStaffAdmin();
        $this->error = null;
        $this->statusMessage = null;

        if (! $this->selectedConversationId) {
            $this->error = 'Select a conversation first.';

            return;
        }

        $this->closeImageEdit();
        $this->pricedSendMessageId = null;
        $this->composerProductPickerOpen = true;
        $this->pricedSendSearch = '';
        $this->pricedSendCategory = '';
        $this->pricedSendPriceMin = '';
        $this->pricedSendPriceMax = '';
        $this->refreshPricedSendResults();
    }

    public function closePricedImageSend(): void
    {
        $this->composerProductPickerOpen = false;
        $this->pricedSendMessageId = null;
        $this->pricedSendSearch = '';
        $this->pricedSendCategory = '';
        $this->pricedSendPriceMin = '';
        $this->pricedSendPriceMax = '';
        $this->pricedSendResults = [];
    }

    /**
     * Re-read persisted photo matches after the browser queue finished one or more
     * (matching itself runs in parallel HTTP requests, not in this component).
     */
    public function refreshInboundImageMatches(): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->syncInboundImageMatchState();
    }

    /**
     * Send the tagged (or auto-matched) catalog product as a priced image reply.
     */
    public function sendPricedImageFromMatch(
        int $messageId,
        InboxPricedImageSender $sender,
    ): void {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        $message = $this->inboundImageMessage($messageId);
        $productId = $this->matchedProductIdForMessage($messageId, $message);

        if (! $message || $productId <= 0) {
            $this->error = 'No product tagged on this image.';

            return;
        }

        $product = Product::query()->whereKey($productId)->first();
        if (! $product) {
            $this->error = 'Tagged product not found.';

            return;
        }

        $conversation = ChannelConversation::query()->find((int) $message->channel_conversation_id);
        if (! $conversation) {
            $this->error = 'Conversation not found.';

            return;
        }

        $this->ensureConversationSelected((int) $conversation->id);

        $result = $sender->send($conversation, $product, $message, auth()->id());

        if (! ($result['ok'] ?? false)) {
            $this->error = $result['error'] ?? 'Failed to send priced image.';

            return;
        }

        $this->statusMessage = 'Priced image sent.';
    }

    /**
     * Reply to the inbound photo with the tagged product's selling price as text.
     */
    public function sendMatchedProductPriceReply(int $messageId, ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        $message = $this->inboundImageMessage($messageId);
        $productId = $this->matchedProductIdForMessage($messageId, $message);

        if (! $message || $productId <= 0) {
            $this->error = 'No product tagged on this image.';

            return;
        }

        $product = Product::query()->whereKey($productId)->first();
        if (! $product) {
            $this->error = 'Tagged product not found.';

            return;
        }

        $conversation = ChannelConversation::query()->find((int) $message->channel_conversation_id);
        if (! $conversation) {
            $this->error = 'Conversation not found.';

            return;
        }

        $this->ensureConversationSelected((int) $conversation->id);

        $body = $product->priceWithUnitLabel();
        $result = $replies->sendText($conversation, $body, false, $message);

        if (! ($result['ok'] ?? false)) {
            $this->error = $result['error'] ?? 'Failed to send price reply.';

            return;
        }

        $this->markConversationRead($conversation, $replies);
        $this->statusMessage = 'Price reply sent.';
    }

    /**
     * Send every catalog (non-priced) image for the tagged product.
     */
    public function sendMatchedProductAlbumImages(int $messageId, ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        $message = $this->inboundImageMessage($messageId);
        $productId = $this->matchedProductIdForMessage($messageId, $message);

        if (! $message || $productId <= 0) {
            $this->error = 'No product tagged on this image.';

            return;
        }

        $product = Product::query()->with(['images' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])->find($productId);
        if (! $product) {
            $this->error = 'Tagged product not found.';

            return;
        }

        $conversation = ChannelConversation::query()->find((int) $message->channel_conversation_id);
        if (! $conversation) {
            $this->error = 'Conversation not found.';

            return;
        }

        $this->ensureConversationSelected((int) $conversation->id);

        $images = $product->images
            ->filter(function ($image) use ($product): bool {
                $path = is_string($image->path) ? trim($image->path) : '';
                if ($path === '') {
                    return false;
                }

                $priced = is_string($product->priced_image_path) ? trim($product->priced_image_path) : '';

                return $priced === '' || ltrim($path, '/') !== ltrim($priced, '/');
            })
            ->values();

        if ($images->isEmpty()) {
            $this->error = 'This product has no catalog images to send.';

            return;
        }

        $sent = 0;
        foreach ($images as $image) {
            try {
                $upload = $this->uploadedFileFromPublicPath((string) $image->path);
            } catch (Throwable $e) {
                $this->error = $e->getMessage();

                return;
            }

            $result = $replies->sendImage(
                $conversation,
                $upload,
                '',
                false,
                $sent === 0 ? $message : null,
            );

            if (! ($result['ok'] ?? false)) {
                $this->error = $result['error'] ?? 'Failed to send product image.';

                return;
            }

            $sent++;
        }

        $this->markConversationRead($conversation, $replies);
        $this->statusMessage = $sent === 1
            ? 'Sent 1 product image.'
            : 'Sent '.$sent.' product images.';
    }

    /**
     * Send the storefront URL for the tagged product.
     */
    public function sendMatchedProductLink(int $messageId, ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        $message = $this->inboundImageMessage($messageId);
        $productId = $this->matchedProductIdForMessage($messageId, $message);

        if (! $message || $productId <= 0) {
            $this->error = 'No product tagged on this image.';

            return;
        }

        $product = Product::query()->whereKey($productId)->first();
        if (! $product) {
            $this->error = 'Tagged product not found.';

            return;
        }

        $conversation = ChannelConversation::query()->find((int) $message->channel_conversation_id);
        if (! $conversation) {
            $this->error = 'Conversation not found.';

            return;
        }

        $this->ensureConversationSelected((int) $conversation->id);

        $result = $replies->sendText($conversation, route('product.show', $product), false, $message);

        if (! ($result['ok'] ?? false)) {
            $this->error = $result['error'] ?? 'Failed to send product link.';

            return;
        }

        $this->markConversationRead($conversation, $replies);
        $this->statusMessage = 'Product link sent.';
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
        InboxPricedImageSender $sender,
    ): void {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        if (! $this->isProductPickerOpen()) {
            return;
        }

        $context = $this->resolveProductPickerContext();
        if ($context === null) {
            return;
        }

        [$conversation, $replyTo] = $context;

        $product = Product::query()->find($productId);
        if (! $product) {
            $this->error = 'Product not found.';

            return;
        }

        $result = $sender->send($conversation, $product, $replyTo, auth()->id());

        if (! ($result['ok'] ?? false)) {
            $this->error = $result['error'] ?? 'Failed to send priced image.';

            return;
        }

        $this->statusMessage = 'Priced image sent.';
    }

    public function sendProductPickerAlbumImages(int $productId, ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        if (! $this->isProductPickerOpen()) {
            return;
        }

        $context = $this->resolveProductPickerContext();
        if ($context === null) {
            return;
        }

        [$conversation, $replyTo] = $context;

        $product = Product::query()->with(['images' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])->find($productId);
        if (! $product) {
            $this->error = 'Product not found.';

            return;
        }

        $images = $product->images
            ->filter(function ($image) use ($product): bool {
                $path = is_string($image->path) ? trim($image->path) : '';
                if ($path === '') {
                    return false;
                }

                $priced = is_string($product->priced_image_path) ? trim($product->priced_image_path) : '';

                return $priced === '' || ltrim($path, '/') !== ltrim($priced, '/');
            })
            ->values();

        if ($images->isEmpty()) {
            $this->error = 'This product has no catalog images to send.';

            return;
        }

        $sent = 0;
        foreach ($images as $image) {
            try {
                $upload = $this->uploadedFileFromPublicPath((string) $image->path);
            } catch (Throwable $e) {
                $this->error = $e->getMessage();

                return;
            }

            $result = $replies->sendImage(
                $conversation,
                $upload,
                '',
                false,
                $sent === 0 ? $replyTo : null,
            );

            if (! ($result['ok'] ?? false)) {
                $this->error = $result['error'] ?? 'Failed to send product image.';

                return;
            }

            $sent++;
        }

        $this->markConversationRead($conversation, $replies);
        $this->closePricedImageSend();
        $this->statusMessage = $sent === 1
            ? 'Sent 1 product image.'
            : 'Sent '.$sent.' product images.';
    }

    public function sendProductPickerLink(int $productId, ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        if (! $this->isProductPickerOpen()) {
            return;
        }

        $context = $this->resolveProductPickerContext();
        if ($context === null) {
            return;
        }

        [$conversation, $replyTo] = $context;

        $product = Product::query()->find($productId);
        if (! $product) {
            $this->error = 'Product not found.';

            return;
        }

        $result = $replies->sendText($conversation, route('product.show', $product), false, $replyTo);

        if (! ($result['ok'] ?? false)) {
            $this->error = $result['error'] ?? 'Failed to send product link.';

            return;
        }

        $this->markConversationRead($conversation, $replies);
        $this->closePricedImageSend();
        $this->statusMessage = 'Product link sent.';
    }

    public function sendProductPickerPrice(int $productId, ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        if (! $this->isProductPickerOpen()) {
            return;
        }

        $context = $this->resolveProductPickerContext();
        if ($context === null) {
            return;
        }

        [$conversation, $replyTo] = $context;

        $product = Product::query()->find($productId);
        if (! $product) {
            $this->error = 'Product not found.';

            return;
        }

        $result = $replies->sendText($conversation, $product->priceWithUnitLabel(), false, $replyTo);

        if (! ($result['ok'] ?? false)) {
            $this->error = $result['error'] ?? 'Failed to send price reply.';

            return;
        }

        $this->markConversationRead($conversation, $replies);
        $this->closePricedImageSend();
        $this->statusMessage = 'Price reply sent.';
    }

    public function sendReply(): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->statusMessage = null;

        if ($this->outboundSending) {
            return;
        }

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
            'replyImages' => ['nullable', 'array', 'max:10'],
            'replyImages.*' => Fileinfo::storedImageItemRules(5120, allowGif: true),
            'replyToMessageId' => ['nullable', 'integer'],
        ]);

        $images = array_values(array_filter(
            $this->replyImages,
            fn ($file) => $file instanceof TemporaryUploadedFile || $file instanceof UploadedFile,
        ));

        if (trim($this->replyText) === '' && $images === []) {
            $this->error = 'Reply text or image is required.';

            return;
        }

        if ($this->replyToMessageId) {
            $replyToExists = ChannelMessage::query()
                ->where('channel_conversation_id', $conversation->id)
                ->whereKey($this->replyToMessageId)
                ->exists();

            if (! $replyToExists) {
                $this->error = 'The message you are replying to was not found.';

                return;
            }
        }

        // Clear the composer immediately; Graph Send API runs in a follow-up request.
        $this->pendingReplyText = $this->replyText;
        $this->pendingReplyImages = $images;
        $this->pendingReplyToMessageId = $this->replyToMessageId;
        $this->outboundSending = true;
        $this->resetComposer();
        $this->dispatchPendingOutboundFlush();
    }

    /**
     * Complete a staged composer send against Messenger (background Livewire request).
     */
    public function flushPendingOutbound(ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

        if (! $this->outboundSending) {
            return;
        }

        if (! $this->selectedConversationId) {
            $this->failPendingOutbound('Conversation not found.');

            return;
        }

        $conversation = ChannelConversation::query()->find($this->selectedConversationId);

        if (! $conversation) {
            $this->failPendingOutbound('Conversation not found.');

            return;
        }

        $replyTo = null;
        if ($this->pendingReplyToMessageId) {
            $replyTo = ChannelMessage::query()
                ->where('channel_conversation_id', $conversation->id)
                ->whereKey($this->pendingReplyToMessageId)
                ->first();

            if (! $replyTo) {
                $this->failPendingOutbound('The message you are replying to was not found.');

                return;
            }
        }

        if ($this->pendingReplyImages !== []) {
            $caption = $this->pendingReplyText;
            $images = array_values($this->pendingReplyImages);

            foreach ($images as $index => $image) {
                $result = $replies->sendImage(
                    $conversation,
                    $image,
                    $index === 0 ? $caption : '',
                    false,
                    $index === 0 ? $replyTo : null,
                );

                if (! ($result['ok'] ?? false)) {
                    $this->pendingReplyImages = array_slice($images, $index);
                    if ($index > 0) {
                        $this->pendingReplyText = '';
                    }
                    $this->failPendingOutbound($result['error'] ?? 'Failed to send reply.');

                    return;
                }
            }
        } else {
            $result = $replies->sendText(
                $conversation,
                $this->pendingReplyText,
                false,
                $replyTo,
            );

            if (! ($result['ok'] ?? false)) {
                $this->failPendingOutbound($result['error'] ?? 'Failed to send reply.');

                return;
            }
        }

        $this->clearPendingOutbound();
        $this->markConversationRead($conversation, $replies);
        $this->statusMessage = 'Reply sent.';
    }

    private function dispatchPendingOutboundFlush(): void
    {
        if (app()->runningUnitTests()) {
            $this->flushPendingOutbound(app(ChannelReplyService::class));

            return;
        }

        $this->js('queueMicrotask(() => $wire.flushPendingOutbound())');
    }

    private function failPendingOutbound(string $error): void
    {
        $this->replyText = $this->pendingReplyText;
        $this->replyImages = array_values($this->pendingReplyImages);
        $this->replyToMessageId = $this->pendingReplyToMessageId;
        $this->clearPendingOutbound();
        $this->error = $error;
        $this->statusMessage = null;
    }

    private function clearPendingOutbound(): void
    {
        $this->pendingReplyText = '';
        $this->pendingReplyImages = [];
        $this->pendingReplyToMessageId = null;
        $this->outboundSending = false;
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
            $this->queueInboundImageMatching();
        }
    }

    /**
     * Background Graph sync while the Inbox tab is open (wire:poll.visible).
     * Quiet on success so the UI is not spammed every poll.
     */
    public function pollSyncFromFacebook(MessengerConversationSyncService $sync): void
    {
        AdminAccess::ensureStaffAdmin();

        $result = $sync->poll();
        $this->recordSyncResult($result, announceSuccess: false);

        // Local read-state only before render. Defer Graph mark_seen so a slow
        // Facebook call cannot delay Livewire morphing newly synced messages.
        $this->refreshOpenThreadAfterPoll();
        $this->deferMessengerSeenForOpenThread();
        $this->queueInboundImageMatching();
    }

    public function dismissSyncToast(): void
    {
        $this->syncToast = null;
    }

    public function dismissStatusMessage(): void
    {
        $this->statusMessage = null;
    }

    public function clearInboxError(): void
    {
        $this->error = null;
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
            $this->queueInboundImageMatching();
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

        return max(5, (int) config('channels.inbox.graph_poll_seconds', 45));
    }

    public function clearFilters(): void
    {
        $this->channel = '';
        $this->unread = '';
        $this->window = '';
        $this->linked = '';
    }

    /**
     * Retry Messenger mark_seen / thread takeover for the open thread.
     * Always invoke markSeen for Messenger threads so take_thread_control can
     * keep retrying even after a prior mark_seen "success" left Page Inbox unread.
     */
    public function retryMessengerSeen(ChannelReplyService $replies): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        $conversation = ChannelConversation::query()->find($this->selectedConversationId);

        if (! $conversation || $conversation->channel !== ChannelConversation::CHANNEL_MESSENGER) {
            return;
        }

        $replies->markSeen($conversation);
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

        if (! $conversation || $conversation->channel !== ChannelConversation::CHANNEL_MESSENGER) {
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
        // Graph mark_seen runs in a follow-up request so conversation open / send stay snappy.
        $conversation->markRead(auth()->id());
        $this->deferMessengerSeenForOpenThread();
    }

    private function resetComposer(): void
    {
        $this->replyText = '';
        $this->replyToMessageId = null;
        $this->replyImages = [];
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
        $this->inboundImageMatchState = [];
    }

    /**
     * Show persisted photo matches for the open thread; unmatched photos become "pending"
     * and are matched by the browser queue (inline in tests).
     */
    private function queueInboundImageMatching(): void
    {
        // Tests resolve inline so they can assert chips without a browser. A test that
        // needs the pending spinner (one request URL per photo) turns this off.
        $resolveInline = app()->runningUnitTests()
            && config('channels.image_match.resolve_inline_in_tests', true);

        $this->syncInboundImageMatchState(resolvePending: $resolveInline);
    }

    private function syncInboundImageMatchState(bool $resolvePending = false): void
    {
        if (! $this->selectedConversationId) {
            $this->inboundImageMatchState = [];

            return;
        }

        $matches = app(ChannelMessageImageMatchService::class);

        $messages = ChannelMessage::query()
            ->where('channel_conversation_id', $this->selectedConversationId)
            ->where('direction', ChannelMessage::DIRECTION_INBOUND)
            ->whereNotNull('media_url')
            ->latest('id')
            ->limit(self::IMAGE_MATCH_THREAD_LIMIT)
            ->get(['id', 'channel_conversation_id', 'direction', 'matched_product_id', 'media_mime', 'media_url', 'media_path', 'raw_payload', 'image_match_checked_at', 'image_match_meta'])
            ->reverse()
            ->filter(fn (ChannelMessage $message): bool => $message->isImageAttachment())
            ->values();

        $health = $messages->isNotEmpty() ? $matches->indexHealth() : null;
        $this->imageMatchNotices = $this->imageMatchNoticesFor($health);

        $stamp = null;
        if ($messages->contains(fn (ChannelMessage $message): bool => $message->image_match_checked_at !== null && ! $message->matched_product_id)) {
            try {
                $stamp = $matches->indexStamp();
            } catch (Throwable $e) {
                Log::warning('Inbox image match index stamp failed.', ['error' => $e->getMessage()]);
            }
        }

        // While catalog photos are still being signed the stamp changes constantly; re-checking
        // stored results then would only churn. They are re-checked once the index is complete.
        $refreshStale = $health !== null && $health['missing'] === 0;
        $stallSeconds = max(15, (int) config('channels.image_match.stall_seconds', 120));

        $pending = [];
        $stale = [];
        $stalled = [];
        $trackedPending = [];
        foreach ($messages as $index => $message) {
            $renderState = $matches->renderState($message, $stamp, $stallSeconds);

            if ($renderState === ChannelMessageImageMatchService::STATE_DONE
                || ($renderState === ChannelMessageImageMatchService::STATE_STALE && ! $refreshStale)) {
                continue;
            }

            if ($resolvePending && $renderState !== ChannelMessageImageMatchService::STATE_STALLED) {
                $messages[$index] = $matches->resolve($message);

                continue;
            }

            if ($renderState === ChannelMessageImageMatchService::STATE_STALLED) {
                $stalled[(int) $message->id] = 'stalled';
            } elseif ($renderState === ChannelMessageImageMatchService::STATE_STALE) {
                $stale[(int) $message->id] = true;
            } else {
                $trackedPending[$message->id.':'.$matches->attemptKey($message)] = true;
                if ($this->imageMatchNeverStarted($message, $matches->attemptKey($message), $stallSeconds)) {
                    $stalled[(int) $message->id] = 'not_started';
                } else {
                    $pending[(int) $message->id] = true;
                }
            }
        }
        $this->imageMatchPendingSince = array_intersect_key($this->imageMatchPendingSince, $trackedPending);

        $productNames = Product::query()
            ->whereIn('id', $messages->pluck('matched_product_id')->filter()->unique())
            ->pluck('name', 'id');

        $suggestions = $matches->suggestionRows(
            $messages->filter(fn (ChannelMessage $message): bool => ! $message->matched_product_id && ! isset($pending[(int) $message->id]) && ! isset($stalled[(int) $message->id])),
            self::IMAGE_MATCH_SUGGESTIONS,
        );

        $state = [];
        foreach ($messages as $message) {
            $key = (string) $message->id;

            if (isset($pending[(int) $message->id])) {
                $state[$key] = [
                    'status' => 'pending',
                    'match_url' => route('admin.inbox.image-match', ['message' => $message, 'attempt' => $matches->attemptKey($message)]),
                ];

                continue;
            }

            if (isset($stalled[(int) $message->id])) {
                $state[$key] = [
                    'status' => 'done',
                    'match_status' => ChannelMessageImageMatchService::STATUS_FAILED,
                    'error' => ChannelMessageImageMatchService::failureMessage($stalled[(int) $message->id]),
                ];

                continue;
            }

            $row = [
                'status' => 'done',
                'match_status' => is_array($message->image_match_meta) ? ($message->image_match_meta['status'] ?? null) : null,
            ];

            if (isset($stale[(int) $message->id])) {
                $row['refresh_url'] = route('admin.inbox.image-match', ['message' => $message, 'stamp' => substr(sha1((string) $stamp), 0, 10)]);
            }

            if (! $message->matched_product_id && ($failure = $matches->failure($message)) !== null) {
                $row['error'] = $failure['message'];
            }

            if ($message->matched_product_id) {
                $row['product_id'] = (int) $message->matched_product_id;
                $row['name'] = (string) ($productNames[(int) $message->matched_product_id] ?? 'Product');
                $row['match_percent'] = $matches->matchPercent($message);
                $row['strategy'] = $matches->strategy($message);
            }

            $rows = $suggestions[(int) $message->id] ?? [];
            if ($rows !== []) {
                $row['suggestions'] = array_map(fn (array $suggestion): array => [
                    'product_id' => $suggestion['product_id'],
                    'name' => $suggestion['name'],
                    'image_url' => $suggestion['image_url'],
                    'match_percent' => $suggestion['match_percent'],
                ], $rows);
            }

            $state[$key] = $row;
        }

        $this->inboundImageMatchState = $state;
    }

    /**
     * True when this inbox page has shown the photo as "pending" for longer than the stall
     * window without its match request ever starting (e.g. the inbox script failed to load).
     * Tracked per page, so another tab or a reload gets its own chance to match.
     */
    private function imageMatchNeverStarted(ChannelMessage $message, string $attemptKey, int $stallSeconds): bool
    {
        if (isset($message->image_match_meta['attempt_started_at'])) {
            return false;
        }

        $key = $message->id.':'.$attemptKey;
        $this->imageMatchPendingSince[$key] ??= now()->getTimestamp();

        return now()->getTimestamp() - $this->imageMatchPendingSince[$key] >= $stallSeconds;
    }

    /**
     * @param  array{total: int, signed: int, unreadable: int, missing: int, cache_writable: bool, index_write_failed: bool}|null  $health
     * @return list<array{message: string, action_url?: string, action_label?: string}>
     */
    private function imageMatchNoticesFor(?array $health): array
    {
        if ($health === null) {
            return [];
        }

        $notices = [];

        if ($health['missing'] > 0) {
            $notices[] = [
                'message' => sprintf(
                    'Photo matching index is incomplete: %s of %s catalog photos indexed, so matching is slower and can miss products.',
                    number_format($health['signed']),
                    number_format($health['total']),
                ),
                'action_url' => route('admin.image-hashes'),
                'action_label' => 'Build photo index',
            ];
        }

        if (! $health['cache_writable'] || $health['index_write_failed']) {
            $notices[] = [
                'message' => 'The server can’t write to storage/framework/cache, so the photo index is rebuilt on every match (slow). Make that folder writable (755 or 775) in your hosting file manager.',
            ];
        }

        return $notices;
    }

    private function clearMappingImageMatchState(): void
    {
        $this->mappingCroppedImage = null;
        $this->mappingImageMatches = [];
        $this->mappingImageMatchError = null;
        $this->mappingCropRegion = null;
    }

    /**
     * Tag modal: list the photo's stored candidates (matching first only if it never ran).
     */
    private function loadStoredImageMatches(ChannelMessage $message): void
    {
        $matches = app(ChannelMessageImageMatchService::class);

        try {
            $rows = $matches->candidateRows($matches->resolve($message), 5, includeAttached: true);
        } catch (Throwable) {
            return;
        }

        if ($rows === []) {
            return;
        }

        $this->mappingImageMatches = $rows;
        $this->mappingImageMatchError = null;
    }

    /**
     * @param  list<array{product_id: int, name: string, sku: ?string, price: float, stock_quantity: int, image_url: ?string, match_percent: float}>  $rows
     */
    private function showCropMatches(array $rows): void
    {
        // Always list matches so staff can confirm before adding; do not auto-apply/close.
        $this->mappingImageMatches = $rows;
        if ($rows === []) {
            $this->mappingImageMatchError = 'No close catalog match. Try a tighter crop or search by name.';
        }
    }

    /**
     * @param  array<string, mixed>  $region
     * @return array{left: float, top: float, width: float, height: float}|null
     */
    private function normalizeCropRegion(array $region): ?array
    {
        $values = [];
        foreach (['left', 'top', 'width', 'height'] as $key) {
            if (! isset($region[$key]) || ! is_numeric($region[$key])) {
                return null;
            }
            $values[$key] = min(1.0, max(0.0, (float) $region[$key]));
        }

        $values['width'] = min($values['width'], 1.0 - $values['left']);
        $values['height'] = min($values['height'], 1.0 - $values['top']);

        if ($values['width'] < 0.02 || $values['height'] < 0.02) {
            return null;
        }

        return $values;
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

    private function matchedProductIdForMessage(int $messageId, ?ChannelMessage $message = null): int
    {
        $message ??= $this->inboundImageMessage($messageId);
        $state = $this->inboundImageMatchState[(string) $messageId]
            ?? $this->inboundImageMatchState[$messageId]
            ?? null;

        return (int) ($message?->matched_product_id
            ?? (is_array($state) ? ($state['product_id'] ?? 0) : 0));
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
        if (! $this->isProductPickerOpen()) {
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

    private function isProductPickerOpen(): bool
    {
        return $this->composerProductPickerOpen || $this->pricedSendMessageId !== null;
    }

    /**
     * @return array{0: ChannelConversation, 1: ?ChannelMessage}|null
     */
    private function resolveProductPickerContext(): ?array
    {
        if ($this->pricedSendMessageId) {
            $replyTo = $this->inboundImageMessage($this->pricedSendMessageId);
            if (! $replyTo) {
                $this->error = 'Image message not found.';
                $this->closePricedImageSend();

                return null;
            }

            $this->ensureConversationSelected((int) $replyTo->channel_conversation_id);
        }

        $conversation = $this->selectedConversationId
            ? ChannelConversation::query()->find($this->selectedConversationId)
            : null;

        if (! $conversation) {
            $this->error = 'Conversation not found.';

            return null;
        }

        $replyTo = null;
        if ($this->pricedSendMessageId) {
            $replyTo = $this->inboundImageMessage($this->pricedSendMessageId);
        } elseif ($this->replyToMessageId) {
            $replyTo = ChannelMessage::query()
                ->where('channel_conversation_id', $conversation->id)
                ->whereKey($this->replyToMessageId)
                ->first();
        }

        return [$conversation, $replyTo];
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

        // Desktop convenience: sticky preview of one thread without writing ?conversation=.
        // Poll/sort must not swap the preview to whichever conversation is newest.
        $displayConversationId = $this->selectedConversationId;
        if ($displayConversationId === null && $conversations->isNotEmpty() && ! $this->mobileThreadOpen) {
            $previewStillListed = $this->desktopPreviewConversationId !== null
                && $conversations->contains(
                    fn (ChannelConversation $conversation): bool => (int) $conversation->id === (int) $this->desktopPreviewConversationId
                );

            if ($previewStillListed) {
                $displayConversationId = (int) $this->desktopPreviewConversationId;
            } else {
                $displayConversationId = (int) $conversations->first()->id;
                $this->desktopPreviewConversationId = $displayConversationId;
            }
        }

        $threadSearch = trim($this->threadMessageSearch);
        $lookbackHours = max(1, (int) config('channels.inbox.thread_lookback_hours', 24));
        $threadSince = now()->subHours($lookbackHours);
        $hasOlderMessages = false;

        // If the selected id is outside the filtered list, still load it for the thread,
        // but never shrink the left list to that single conversation.
        // Do not Cache Eloquent graphs — file/redis serialization can return __PHP_Incomplete_Class.
        $selectedConversation = $displayConversationId
            ? ChannelConversation::query()
                ->with([
                    'draftOrder.items',
                    'messages' => function ($q) use ($threadSearch, $threadSince) {
                        $q->with([
                            'replyTo',
                            'matchedProduct:id,name,sku,price,slug',
                            'matchedProduct.images' => fn ($images) => $images->orderByDesc('is_primary')->orderBy('sort_order')->limit(1),
                        ])->orderBy('sent_at')->orderBy('id');

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

        $messengerSeenPendingHint = 'Graph mark_seen has not caught up yet. If Facebook Page Inbox stays unread, set this Meta app as Primary Receiver (or allow Conversation Routing takeover).';
        if ($selectedConversation?->needsMessengerSeenSync()) {
            $lastError = app(ChannelReplyService::class)->lastMarkSeenError($selectedConversation);
            if (is_string($lastError) && $lastError !== '') {
                $messengerSeenPendingHint = $lastError;
            }
        }

        return view('livewire.admin.admin-inbox', [
            'conversations' => $conversations,
            'selectedConversation' => $selectedConversation,
            'inboxOpenThread' => $selectedConversation
                ? app(InboxSnapshotBuilder::class)->thread($selectedConversation, $lookbackHours)
                : null,
            'replyToMessage' => $replyToMessage,
            'mappingMessage' => $mappingMessage,
            'imageEditMessage' => $imageEditMessage,
            'pricedSendMessage' => $pricedSendMessage,
            'pricedSendCategories' => $this->isProductPickerOpen()
                ? Category::query()->orderBy('name')->get(['id', 'name'])
                : collect(),
            'composerProductPickerOpen' => $this->composerProductPickerOpen,
            'quickReplies' => $quickReplies,
            'hasOlderMessages' => $hasOlderMessages,
            'threadLookbackHours' => $lookbackHours,
            'realtimeEnabled' => $this->realtimeEnabled(),
            'graphPollSeconds' => $this->graphPollSeconds(),
            'messengerSeenPendingHint' => $messengerSeenPendingHint,
            'diagnostics' => $diagnostics->forInbox([
                'channel' => $this->channel,
                'unread' => $this->unread,
                'window' => $this->window,
                'linked' => $this->linked,
            ]),
        ]);
    }
}
