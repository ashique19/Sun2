<?php

namespace App\Livewire\Admin;

use App\Models\ChannelConversation;
use App\Services\Channels\ChannelReplyService;
use App\Support\AdminAccess;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Admin Inbox')]
#[Layout('components.layouts.admin')]
class AdminInbox extends Component
{
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

    public ?string $error = null;

    public ?string $message = null;

    public function mount(): void
    {
        AdminAccess::ensureStaffAdmin();
    }

    public function selectConversation(int $conversationId): void
    {
        $conversation = ChannelConversation::query()->findOrFail($conversationId);
        $conversation->markRead(auth()->id());
        $this->selectedConversationId = $conversation->id;
        $this->replyText = '';
        $this->error = null;
        $this->message = null;
    }

    public function sendReply(ChannelReplyService $replies): void
    {
        AdminAccess::ensureStaffAdmin();

        $this->error = null;
        $this->message = null;

        if (! $this->selectedConversationId) {
            return;
        }

        $conversation = ChannelConversation::query()->find($this->selectedConversationId);

        if (! $conversation) {
            $this->error = 'Conversation not found.';

            return;
        }

        $result = $replies->sendText($conversation, $this->replyText);

        if (! $result['ok']) {
            $this->error = $result['error'] ?? 'Failed to send reply.';

            return;
        }

        $conversation->markRead(auth()->id());
        $this->replyText = '';
        $this->message = 'Reply sent.';
    }

    public function render()
    {
        $query = ChannelConversation::query()
            ->with([
                'draftOrder:id,order_number,status',
                'messages' => fn ($q) => $q->latest('sent_at')->limit(1),
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

        if ($this->selectedConversationId === null && $conversations->isNotEmpty()) {
            $this->selectedConversationId = (int) $conversations->first()->id;
        }

        $selectedConversation = $this->selectedConversationId
            ? ChannelConversation::query()
                ->with([
                    'draftOrder:id,order_number,status',
                    'messages' => fn ($q) => $q->orderBy('sent_at')->orderBy('id'),
                ])
                ->find($this->selectedConversationId)
            : null;

        return view('livewire.admin.admin-inbox', [
            'conversations' => $conversations,
            'selectedConversation' => $selectedConversation,
        ]);
    }
}
