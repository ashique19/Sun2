<?php

namespace App\Livewire\Admin;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use App\Services\Channels\ChannelInboxDiagnostics;
use App\Services\Channels\ChannelReplyService;
use App\Services\Channels\MessengerConversationSyncService;
use App\Support\AdminAccess;
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

    #[Url(as: 'conversation')]
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

    public function mount(ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

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
        $this->markConversationRead($conversation, $replies);
        $this->selectedConversationId = $conversation->id;
        $this->mobileThreadOpen = true;
        $this->resetComposer();
        $this->error = null;
        $this->statusMessage = null;
    }

    public function closeMobileThread(): void
    {
        $this->mobileThreadOpen = false;
    }

    public function toggleMobileFilters(): void
    {
        $this->mobileFiltersOpen = ! $this->mobileFiltersOpen;
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

        if (! $result['ok']) {
            $this->error = $result['message'];

            return;
        }

        $this->statusMessage = $result['message'];

        if ($this->selectedConversationId === null) {
            $first = ChannelConversation::query()
                ->orderByRaw('COALESCE(last_inbound_at, last_outbound_at, created_at) desc')
                ->value('id');
            if ($first) {
                $this->selectedConversationId = (int) $first;
            }
        }
    }

    /**
     * Background Graph sync while the Inbox tab is open (wire:poll.visible).
     * Quiet on success so the UI is not spammed every poll.
     */
    public function pollSyncFromFacebook(
        MessengerConversationSyncService $sync,
        ChannelReplyService $replies,
    ): void {
        AdminAccess::ensureStaffAdmin();

        $sync->sync();
        $this->refreshInbox($replies);
    }

    public function clearFilters(): void
    {
        $this->channel = '';
        $this->unread = '';
        $this->window = '';
        $this->linked = '';
    }

    /**
     * Lightweight poll refresh for conversation list + open thread.
     * Also retries Messenger mark_seen until Graph catches up to latest inbound.
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

    private function markConversationRead(ChannelConversation $conversation, ChannelReplyService $replies): void
    {
        $conversation->markRead(auth()->id());
        $replies->markSeen($conversation);
    }

    private function resetComposer(): void
    {
        $this->replyText = '';
        $this->replyToMessageId = null;
        $this->replyImage = null;
    }

    public function render(ChannelInboxDiagnostics $diagnostics)
    {
        $query = ChannelConversation::query()
            ->with([
                'draftOrder:id,order_number,status',
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

        // Keep selection stable; only auto-pick when nothing is selected yet.
        if ($this->selectedConversationId === null && $conversations->isNotEmpty()) {
            $this->selectedConversationId = (int) $conversations->first()->id;
        }

        // If the selected id is outside the filtered list, still load it for the thread,
        // but never shrink the left list to that single conversation.
        $selectedConversation = $this->selectedConversationId
            ? ChannelConversation::query()
                ->with([
                    'draftOrder:id,order_number,status',
                    'messages' => fn ($q) => $q->with('replyTo')->orderBy('sent_at')->orderBy('id'),
                ])
                ->find($this->selectedConversationId)
            : null;

        $replyToMessage = null;
        if ($selectedConversation && $this->replyToMessageId) {
            $replyToMessage = $selectedConversation->messages->firstWhere('id', $this->replyToMessageId);
        }

        return view('livewire.admin.admin-inbox', [
            'conversations' => $conversations,
            'selectedConversation' => $selectedConversation,
            'replyToMessage' => $replyToMessage,
            'diagnostics' => $diagnostics->forInbox([
                'channel' => $this->channel,
                'unread' => $this->unread,
                'window' => $this->window,
                'linked' => $this->linked,
            ]),
        ]);
    }
}
