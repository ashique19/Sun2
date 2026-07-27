<div class="space-y-6">
    <livewire:admin.admin-facebook-token-gate />

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Inbox</h1>
            <p class="mt-1 text-sm text-[#8C8474]">Messenger and WhatsApp conversations in one place.</p>
        </div>

        <div class="flex flex-wrap gap-2 text-sm">
            <select wire:model.live="channel" class="rounded-lg border border-[#E0D6C2] px-3 py-2">
                <option value="">All channels</option>
                <option value="messenger">Messenger</option>
                <option value="whatsapp">WhatsApp</option>
            </select>
            <select wire:model.live="unread" class="rounded-lg border border-[#E0D6C2] px-3 py-2">
                <option value="">All reads</option>
                <option value="1">Unread only</option>
            </select>
            <select wire:model.live="window" class="rounded-lg border border-[#E0D6C2] px-3 py-2">
                <option value="">All windows</option>
                <option value="1">Within 24h only</option>
            </select>
            <select wire:model.live="linked" class="rounded-lg border border-[#E0D6C2] px-3 py-2">
                <option value="">All links</option>
                <option value="1">Linked draft/order</option>
            </select>
        </div>
    </div>

    @if ($conversations->isEmpty() || $diagnostics['severity'] !== 'ok')
        @php
            $box = match ($diagnostics['severity']) {
                'error' => 'border-rose-200 bg-rose-50 text-rose-900',
                'warning' => 'border-amber-200 bg-amber-50 text-amber-950',
                default => 'border-[#E0D6C2] bg-[#FAF6EF] text-[#1E1E1E]',
            };
        @endphp
        <div class="rounded-xl border p-4 sm:p-5 {{ $box }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">
                        @if ($conversations->isEmpty())
                            Inbox status
                        @else
                            Messenger connection notes
                        @endif
                    </h2>
                    <p class="mt-1 text-sm opacity-90">{{ $diagnostics['summary'] }}</p>
                    <p class="mt-2 text-xs opacity-80">
                        Inbox does not pull chat history from Facebook. It only lists conversations Meta already delivered to
                        <code class="rounded bg-white/70 px-1.5 py-0.5">{{ $diagnostics['webhook_url'] }}</code>.
                    </p>
                </div>
                @if ($diagnostics['filters_active'] && $conversations->isEmpty())
                    <button type="button" wire:click="clearFilters"
                        class="rounded-full border border-current/20 bg-white px-3 py-1.5 text-xs font-semibold hover:bg-white/80">
                        Clear filters
                    </button>
                @endif
            </div>

            <ul class="mt-4 space-y-2 text-sm">
                @foreach ($diagnostics['checks'] as $check)
                    <li class="flex gap-2">
                        <span class="mt-0.5 shrink-0 {{ $check['ok'] ? 'text-emerald-700' : 'text-rose-700' }}">
                            {{ $check['ok'] ? '✓' : '!' }}
                        </span>
                        <div class="min-w-0">
                            <div class="font-medium">{{ $check['label'] }}</div>
                            <div class="text-xs opacity-80 break-words">{{ $check['detail'] }}</div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
        <div class="overflow-hidden rounded-xl border border-[#EFE7D6] bg-white">
            <div class="border-b border-[#E7DFCF] px-4 py-3 text-sm font-medium">
                Conversations
                <span class="ml-1 text-xs font-normal text-[#8C8474]">
                    ({{ $conversations->count() }} shown
                    @if ($diagnostics['total_conversations'] !== $conversations->count())
                        / {{ $diagnostics['total_conversations'] }} total
                    @endif
                    )
                </span>
            </div>
            <div class="max-h-[75vh] overflow-y-auto divide-y divide-[#E7DFCF]">
                @forelse ($conversations as $conversation)
                    @php
                        $selected = $selectedConversation?->id === $conversation->id;
                        $latest = $conversation->messages->first();
                    @endphp
                    <button type="button"
                        wire:click="selectConversation({{ $conversation->id }})"
                        class="block w-full px-4 py-3 text-left transition {{ $selected ? 'bg-[#FAF6EF]' : 'hover:bg-[#FAF6EF]/60' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full bg-[#F1EADB] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[#6B6459]">
                                        {{ $conversation->channel }}
                                    </span>
                                    @if ($conversation->isUnread())
                                        <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold text-rose-700">Unread</span>
                                    @endif
                                    @if ($conversation->draftOrder)
                                        <span class="rounded-full bg-[#EEF4FF] px-2 py-0.5 text-[10px] font-semibold text-[#315AA9]">
                                            {{ $conversation->draftOrder->order_number }}
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-1 truncate font-medium text-[#1E1E1E]">
                                    {{ $conversation->customer_name ?: $conversation->customer_phone ?: $conversation->external_user_id }}
                                </p>
                                @if ($latest)
                                    <p class="mt-1 truncate text-xs text-[#8C8474]">
                                        {{ $latest->direction === 'outbound' ? 'You: ' : '' }}{{ $latest->body ?: 'Attachment' }}
                                    </p>
                                @endif
                            </div>
                            <div class="shrink-0 text-[11px] text-[#8C8474]">
                                {{ optional($conversation->last_inbound_at ?: $conversation->last_outbound_at ?: $conversation->created_at)->timezone('Asia/Dhaka')->format('d M h:i A') }}
                            </div>
                        </div>
                    </button>
                @empty
                    <div class="px-4 py-10 text-center text-sm text-[#8C8474]">
                        @if ($diagnostics['filtered_out'])
                            No conversations match the current filters.
                        @else
                            No conversations stored yet.
                        @endif
                    </div>
                @endforelse
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-[#EFE7D6] bg-white">
            @if ($selectedConversation)
                <div class="border-b border-[#E7DFCF] px-4 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="font-semibold text-[#1E1E1E]">
                                {{ ucfirst($selectedConversation->channel) }} · {{ $selectedConversation->customer_name ?: $selectedConversation->customer_phone ?: $selectedConversation->external_user_id }}
                            </h2>
                            <p class="mt-1 text-xs text-[#8C8474]">
                                @if ($selectedConversation->isWithinMessagingWindow())
                                    Within 24h reply window
                                @else
                                    Outside 24h window — customer must message first
                                @endif
                                @if ($selectedConversation->draftOrder)
                                    ·
                                    <a href="{{ route('admin.orders.show', $selectedConversation->draftOrder) }}" class="text-[#C9A227] hover:underline">
                                        View {{ $selectedConversation->draftOrder->order_number }}
                                    </a>
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex max-h-[60vh] flex-col">
                    <div class="flex-1 space-y-3 overflow-y-auto px-4 py-4">
                        @foreach ($selectedConversation->messages as $messageRow)
                            <div @class([
                                'max-w-[90%] rounded-lg px-3 py-2 text-sm',
                                'ml-auto bg-[#C9A227]/15 text-[#1E1E1E]' => $messageRow->direction === 'outbound',
                                'mr-auto bg-[#FAF6EF] text-[#1E1E1E]' => $messageRow->direction !== 'outbound',
                            ])>
                                @if (filled($messageRow->body))
                                    <p class="whitespace-pre-wrap break-words">{{ $messageRow->body }}</p>
                                @endif
                                @if (filled($messageRow->media_url))
                                    <a href="{{ $messageRow->media_url }}" target="_blank" rel="noopener" class="mt-1 inline-block text-xs text-[#C9A227] hover:underline">View attachment</a>
                                @endif
                                @if ($messageRow->sent_at)
                                    <p class="mt-1 text-[10px] text-[#8C8474]">
                                        {{ $messageRow->sent_at->timezone('Asia/Dhaka')->format('d M Y, h:i A') }}
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="border-t border-[#E7DFCF] px-4 py-3">
                        @if ($error)
                            <p class="mb-2 text-xs text-rose-600">{{ $error }}</p>
                        @endif
                        @if ($message)
                            <p class="mb-2 text-xs text-emerald-700">{{ $message }}</p>
                        @endif
                        <div class="flex gap-2">
                            <input type="text" wire:model="replyText" placeholder="Reply…"
                                class="min-w-0 flex-1 rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"
                                wire:keydown.enter.prevent="sendReply">
                            <button type="button"
                                wire:click="sendReply"
                                wire:loading.attr="disabled"
                                class="rounded-lg bg-[#C9A227] px-3 py-2 text-sm font-semibold text-white hover:bg-[#b89220] disabled:opacity-60">
                                Send
                            </button>
                        </div>
                    </div>
                </div>
            @else
                <div class="px-4 py-16 text-center text-sm text-[#8C8474]">
                    @if ($conversations->isEmpty())
                        Once a Messenger webhook arrives, conversations will appear on the left.
                    @else
                        Select a conversation to read and reply.
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
