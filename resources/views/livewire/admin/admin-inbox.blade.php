@php
    $activeFilterCount = collect([$channel, $unread, $window, $linked])
        ->filter(fn (string $value) => $value !== '')
        ->count();
@endphp

<div class="space-y-4 xl:space-y-6" wire:poll.5s.visible="refreshInbox">
    <livewire:admin.admin-facebook-token-gate />

    {{-- Header: compact on mobile, full controls on desktop. Hidden on mobile while reading a thread. --}}
    <div @class([
        'flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between xl:gap-4',
        'hidden xl:flex' => $mobileThreadOpen,
    ])>
        <div class="min-w-0">
            <h1 class="font-serif text-2xl font-semibold xl:text-3xl">Inbox</h1>
            <p class="mt-0.5 hidden text-sm text-[#8C8474] sm:block">
                Messenger and WhatsApp conversations in one place.
                New messages arrive via webhooks; Graph sync runs on a schedule (or use the button).
            </p>
        </div>

        <div class="flex flex-col gap-2 text-sm xl:items-end">
            <div class="flex gap-2">
                <button type="button"
                    wire:click="syncFromFacebook"
                    wire:loading.attr="disabled"
                    wire:target="syncFromFacebook"
                    class="inline-flex flex-1 items-center justify-center rounded-full border border-[#E0D6C2] bg-white px-3 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227] disabled:opacity-60 sm:flex-none sm:px-4">
                    <span wire:loading.remove wire:target="syncFromFacebook">Sync from Facebook</span>
                    <span wire:loading wire:target="syncFromFacebook">Syncing…</span>
                </button>

                <button type="button"
                    wire:click="toggleMobileFilters"
                    aria-expanded="{{ $mobileFiltersOpen ? 'true' : 'false' }}"
                    class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-full border border-[#E0D6C2] bg-white px-3 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227] sm:flex-none xl:hidden">
                    Filters
                    @if ($activeFilterCount > 0)
                        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-[#C9A227] px-1.5 text-[10px] font-bold text-white">
                            {{ $activeFilterCount }}
                        </span>
                    @endif
                </button>
            </div>

            {{-- Single filter set: panel on small screens, inline from xl --}}
            <div @class([
                'grid w-full grid-cols-2 gap-2 rounded-xl border border-[#EFE7D6] bg-white p-3 xl:flex xl:w-auto xl:flex-wrap xl:items-center xl:gap-2 xl:rounded-none xl:border-0 xl:bg-transparent xl:p-0',
                'hidden' => ! $mobileFiltersOpen,
            ])>
                <select wire:model.live="channel" class="rounded-lg border border-[#E0D6C2] px-2.5 py-2 xl:px-3">
                    <option value="">All channels</option>
                    <option value="messenger">Messenger</option>
                    <option value="whatsapp">WhatsApp</option>
                </select>
                <select wire:model.live="unread" class="rounded-lg border border-[#E0D6C2] px-2.5 py-2 xl:px-3">
                    <option value="">All reads</option>
                    <option value="1">Unread only</option>
                </select>
                <select wire:model.live="window" class="rounded-lg border border-[#E0D6C2] px-2.5 py-2 xl:px-3">
                    <option value="">All windows</option>
                    <option value="1">Within 24h only</option>
                </select>
                <select wire:model.live="linked" class="rounded-lg border border-[#E0D6C2] px-2.5 py-2 xl:px-3">
                    <option value="">All links</option>
                    <option value="1">Linked draft/order</option>
                </select>
                @if ($activeFilterCount > 0)
                    <button type="button" wire:click="clearFilters"
                        class="col-span-2 rounded-full border border-[#E0D6C2] bg-[#FAF6EF] px-3 py-2 text-xs font-semibold text-[#6B6459] xl:hidden">
                        Clear filters
                    </button>
                @endif
            </div>
        </div>
    </div>

    @if ($error)
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-900 xl:px-4 xl:py-3">{{ $error }}</div>
    @endif
    @if ($statusMessage)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-900 xl:px-4 xl:py-3">{{ $statusMessage }}</div>
    @endif

    @if ($conversations->isEmpty() || $diagnostics['severity'] !== 'ok')
        @php
            $box = match ($diagnostics['severity']) {
                'error' => 'border-rose-200 bg-rose-50 text-rose-900',
                'warning' => 'border-amber-200 bg-amber-50 text-amber-950',
                default => 'border-[#E0D6C2] bg-[#FAF6EF] text-[#1E1E1E]',
            };
        @endphp
        <div @class([
            'rounded-xl border p-3 sm:p-4 xl:p-5',
            $box,
            'hidden xl:block' => $mobileThreadOpen && $conversations->isNotEmpty(),
        ])>
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
                    <details class="mt-2 xl:hidden">
                        <summary class="cursor-pointer text-xs font-medium opacity-80">Connection details</summary>
                        <p class="mt-2 text-xs opacity-80">
                            Webhooks only receive chats Meta delivers to
                            <code class="rounded bg-white/70 px-1.5 py-0.5">{{ $diagnostics['webhook_url'] }}</code>
                            <code class="rounded bg-white/70 px-1.5 py-0.5">messages</code> +
                            <code class="rounded bg-white/70 px-1.5 py-0.5">standby</code>).
                            <strong>Development mode:</strong> even as Page owner, only Facebook accounts that are App Admins/Developers/Testers will appear.
                            Add other accounts under Meta App → App Roles → Roles (Testers), or switch the app to <strong>Live</strong>.
                            Use <strong>Sync from Facebook</strong> to import threads Graph can currently see.
                        </p>
                    </details>
                    <p class="mt-2 hidden text-xs opacity-80 xl:block">
                        Webhooks only receive chats Meta delivers to
                        <code class="rounded bg-white/70 px-1.5 py-0.5">{{ $diagnostics['webhook_url'] }}</code>
                        <code class="rounded bg-white/70 px-1.5 py-0.5">messages</code> +
                        <code class="rounded bg-white/70 px-1.5 py-0.5">standby</code>).
                        <strong>Development mode:</strong> even as Page owner, only Facebook accounts that are App Admins/Developers/Testers will appear.
                        Add other accounts under Meta App → App Roles → Roles (Testers), or switch the app to <strong>Live</strong>.
                        Use <strong>Sync from Facebook</strong> to import threads Graph can currently see.
                    </p>
                </div>
                @if ($diagnostics['filters_active'] && $conversations->isEmpty())
                    <button type="button" wire:click="clearFilters"
                        class="rounded-full border border-current/20 bg-white px-3 py-1.5 text-xs font-semibold hover:bg-white/80">
                        Clear filters
                    </button>
                @endif
            </div>

            <ul class="mt-3 space-y-2 text-sm xl:mt-4">
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

    @if ($diagnostics['filters_active'] && $conversations->count() !== $diagnostics['total_conversations'])
        <div @class([
            'flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950 xl:px-4 xl:py-3',
            'hidden xl:flex' => $mobileThreadOpen,
        ])>
            <p>
                Showing {{ $conversations->count() }} of {{ $diagnostics['total_conversations'] }} conversations
                because filters are active.
            </p>
            <button type="button" wire:click="clearFilters"
                class="rounded-full border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold hover:bg-amber-100">
                Clear filters
            </button>
        </div>
    @endif

    <div class="grid gap-4 xl:grid-cols-[22rem_minmax(0,1fr)] xl:items-start xl:gap-6">
        {{-- Conversation list: full-screen on mobile when thread closed --}}
        <div @class([
            'flex flex-col overflow-hidden rounded-xl border border-[#EFE7D6] bg-white',
            'h-[calc(100dvh-11rem)] max-h-[calc(100dvh-11rem)] xl:h-auto xl:max-h-[75vh]',
            $mobileThreadOpen ? 'hidden xl:flex' : 'flex',
        ])>
            <div class="shrink-0 border-b border-[#E7DFCF] px-3 py-2.5 text-sm font-medium xl:px-4 xl:py-3">
                Conversations
                <span class="ml-1 text-xs font-normal text-[#8C8474]">
                    ({{ $conversations->count() }} shown
                    @if ($diagnostics['total_conversations'] !== $conversations->count())
                        / {{ $diagnostics['total_conversations'] }} total
                    @endif
                    )
                </span>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto divide-y divide-[#E7DFCF]">
                @forelse ($conversations as $conversation)
                    @php
                        $selected = $selectedConversation?->id === $conversation->id;
                        $latest = $conversation->latestMessage;
                        $channelShort = $conversation->channel === 'whatsapp' ? 'WA' : 'MSG';
                        $displayName = $conversation->customer_name ?: $conversation->customer_phone ?: $conversation->external_user_id;
                        $timestamp = optional($conversation->last_inbound_at ?: $conversation->last_outbound_at ?: $conversation->created_at)
                            ->timezone('Asia/Dhaka');
                    @endphp
                    <button type="button"
                        wire:key="inbox-conversation-{{ $conversation->id }}"
                        wire:click="selectConversation({{ $conversation->id }})"
                        @class([
                            'block w-full px-3 py-2.5 text-left transition xl:px-4 xl:py-3',
                            $selected ? 'bg-[#FAF6EF]' : 'hover:bg-[#FAF6EF]/60',
                        ])>
                        <div class="flex items-center gap-2">
                            @if ($conversation->isUnread())
                                <span class="h-2 w-2 shrink-0 rounded-full bg-rose-500" title="Unread" aria-label="Unread"></span>
                            @else
                                <span class="h-2 w-2 shrink-0 rounded-full bg-transparent" aria-hidden="true"></span>
                            @endif
                            <p class="min-w-0 flex-1 truncate text-sm font-semibold text-[#1E1E1E]">
                                {{ $displayName }}
                            </p>
                            <span class="shrink-0 text-[10px] tabular-nums text-[#8C8474]">
                                {{ $timestamp?->format('d M h:i A') }}
                            </span>
                        </div>
                        <div class="mt-0.5 flex items-center gap-1.5 pl-4">
                            <span class="shrink-0 text-[10px] font-semibold uppercase tracking-wide text-[#8C8474]">
                                {{ $channelShort }}
                            </span>
                            @if ($conversation->draftOrder)
                                <span class="shrink-0 truncate text-[10px] font-semibold text-[#315AA9]">
                                    {{ $conversation->draftOrder->order_number }}
                                </span>
                            @endif
                            @if ($latest)
                                @if ($latest->isImageAttachment())
                                    <img
                                        src="{{ route('admin.inbox.media', $latest) }}"
                                        alt=""
                                        class="h-5 w-5 shrink-0 rounded object-cover bg-[#F1EADB]"
                                        loading="lazy">
                                @endif
                                <p class="min-w-0 flex-1 truncate text-xs text-[#8C8474]">
                                    {{ $latest->direction === 'outbound' ? 'You: ' : '' }}{{ $latest->body ?: ($latest->isImageAttachment() ? 'Photo' : 'Attachment') }}
                                </p>
                            @else
                                <p class="min-w-0 flex-1 truncate text-xs text-[#8C8474]">No messages yet</p>
                            @endif
                        </div>
                        <span class="sr-only">
                            @if ($conversation->isUnread()) Unread @endif
                            {{ $conversation->channel }}
                        </span>
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

        {{-- Thread: full-screen on mobile when open; always visible on desktop --}}
        <div @class([
            'flex flex-col overflow-hidden rounded-xl border border-[#EFE7D6] bg-white',
            'h-[calc(100dvh-8.5rem)] max-h-[calc(100dvh-8.5rem)] xl:h-[75vh] xl:max-h-[75vh]',
            $mobileThreadOpen ? 'flex' : 'hidden xl:flex',
        ])>
            @if ($selectedConversation)
                <div class="shrink-0 border-b border-[#E7DFCF] px-3 py-2.5 xl:px-4 xl:py-3">
                    <div class="flex items-start gap-2">
                        <button type="button"
                            wire:click="closeMobileThread"
                            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-[#E0D6C2] text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227] xl:hidden"
                            aria-label="Back to conversations">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                                <path fill-rule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.56l3.22 3.22a.75.75 0 1 1-1.06 1.06l-4.5-4.5a.75.75 0 0 1 0-1.06l4.5-4.5a.75.75 0 0 1 1.06 1.06L5.56 9.25H16.25A.75.75 0 0 1 17 10Z" clip-rule="evenodd" />
                            </svg>
                        </button>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate font-semibold text-[#1E1E1E]">
                                {{ $selectedConversation->customer_name ?: $selectedConversation->customer_phone ?: $selectedConversation->external_user_id }}
                            </h2>
                            <p class="mt-0.5 text-xs text-[#8C8474]">
                                {{ ucfirst($selectedConversation->channel) }}
                                ·
                                @if ($selectedConversation->isWithinMessagingWindow())
                                    Within 24h
                                @else
                                    Outside 24h window
                                @endif
                                @if ($selectedConversation->draftOrder)
                                    ·
                                    <a href="{{ route('admin.orders.show', $selectedConversation->draftOrder) }}" class="text-[#C9A227] hover:underline">
                                        {{ $selectedConversation->draftOrder->order_number }}
                                    </a>
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    wire:key="thread-{{ $selectedConversation->id }}-{{ $selectedConversation->messages->count() }}-{{ $selectedConversation->messages->max('id') }}"
                    x-init="$el.scrollTop = $el.scrollHeight"
                    class="min-h-0 flex-1 space-y-3 overflow-y-auto px-3 py-3 xl:px-4 xl:py-4">
                    @foreach ($selectedConversation->messages as $messageRow)
                        <div @class([
                            'group relative max-w-[90%] rounded-lg px-3 py-2 text-sm',
                            'ml-auto bg-[#C9A227]/15 text-[#1E1E1E]' => $messageRow->direction === 'outbound',
                            'mr-auto bg-[#FAF6EF] text-[#1E1E1E]' => $messageRow->direction !== 'outbound',
                        ])>
                            @if ($messageRow->replyTo)
                                <div class="mb-2 rounded border border-[#E7DFCF] bg-white/70 px-2 py-1 text-[11px] text-[#6B6459]">
                                    <span class="font-semibold">Replying to</span>
                                    <span class="block truncate">{{ $messageRow->replyTo->previewText() }}</span>
                                </div>
                            @endif

                            @if (filled($messageRow->body))
                                <p class="whitespace-pre-wrap break-words">{{ $messageRow->body }}</p>
                            @endif
                            @if ($messageRow->isImageAttachment())
                                <a href="{{ route('admin.inbox.media', $messageRow) }}" target="_blank" rel="noopener"
                                    class="mt-2 block overflow-hidden rounded-lg border border-[#E7DFCF] bg-white">
                                    <img
                                        src="{{ route('admin.inbox.media', $messageRow) }}"
                                        alt="Photo"
                                        class="max-h-64 w-full object-contain bg-[#FAF6EF]"
                                        loading="lazy">
                                </a>
                            @elseif ($messageRow->hasMedia())
                                <a href="{{ route('admin.inbox.media', $messageRow) }}" target="_blank" rel="noopener"
                                    class="mt-1 inline-block text-xs text-[#C9A227] hover:underline">
                                    View attachment
                                </a>
                            @endif
                            <div class="mt-1 flex items-center justify-between gap-2">
                                @if ($messageRow->sent_at)
                                    <p class="text-[10px] text-[#8C8474]">
                                        {{ $messageRow->sent_at->timezone('Asia/Dhaka')->format('d M Y, h:i A') }}
                                    </p>
                                @else
                                    <span></span>
                                @endif
                                <button type="button"
                                    wire:click="setReplyTo({{ $messageRow->id }})"
                                    class="text-[10px] font-medium text-[#C9A227] opacity-80 hover:opacity-100 hover:underline">
                                    Reply
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="shrink-0 border-t border-[#E7DFCF] bg-white px-3 py-2.5 xl:px-4 xl:py-3">
                    @if ($error)
                        <p class="mb-2 text-xs text-rose-600">{{ $error }}</p>
                    @endif
                    @if ($statusMessage)
                        <p class="mb-2 text-xs text-emerald-700">{{ $statusMessage }}</p>
                    @endif

                    @if ($replyToMessage)
                        <div class="mb-2 flex items-start justify-between gap-3 rounded-lg border border-[#E7DFCF] bg-[#FAF6EF] px-3 py-2">
                            <div class="min-w-0">
                                <div class="text-[11px] font-semibold text-[#6B6459]">Replying to</div>
                                <div class="truncate text-xs text-[#1E1E1E]">{{ $replyToMessage->previewText(120) }}</div>
                            </div>
                            <button type="button" wire:click="clearReplyTo" class="shrink-0 text-xs text-[#8C8474] hover:text-[#1E1E1E]">
                                Cancel
                            </button>
                        </div>
                    @endif

                    @if ($replyImage)
                        <div class="mb-2 flex items-center gap-3 rounded-lg border border-[#E7DFCF] bg-[#FAF6EF] px-3 py-2">
                            <img src="{{ $replyImage->temporaryUrl() }}" alt="" class="h-12 w-12 rounded object-cover">
                            <div class="min-w-0 flex-1 text-xs text-[#6B6459]">Image ready to send</div>
                            <button type="button" wire:click="clearReplyImage" class="text-xs text-[#8C8474] hover:text-[#1E1E1E]">
                                Remove
                            </button>
                        </div>
                    @endif
                    @error('replyImage')
                        <p class="mb-2 text-xs text-rose-600">{{ $message }}</p>
                    @enderror

                    <div class="flex items-end gap-2">
                        <label class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-[#E0D6C2] text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]" title="Attach image">
                            <input type="file" class="hidden" wire:model="replyImage" accept="image/*">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                                <path fill-rule="evenodd" d="M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm4.78 1.47a.75.75 0 0 0-1.06 1.06l2.5 2.5a.75.75 0 0 0 1.06 0l1.72-1.72 3.22 3.22a.75.75 0 1 0 1.06-1.06l-3.75-3.75a.75.75 0 0 0-1.06 0L7.28 8.72 5.78 7.22Z" clip-rule="evenodd" />
                            </svg>
                        </label>
                        <div class="min-w-0 flex-1">
                            <div wire:loading wire:target="replyImage" class="mb-1 text-[11px] text-[#8C8474]">Uploading image…</div>
                            <input type="text" wire:model="replyText" placeholder="{{ $replyToMessage ? 'Write a reply…' : 'Message…' }}"
                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"
                                wire:keydown.enter.prevent="sendReply">
                        </div>
                        <button type="button"
                            wire:click="sendReply"
                            wire:loading.attr="disabled"
                            class="rounded-lg bg-[#C9A227] px-3 py-2 text-sm font-semibold text-white hover:bg-[#b89220] disabled:opacity-60">
                            Send
                        </button>
                    </div>
                </div>
            @else
                <div class="flex flex-1 items-center justify-center px-4 py-16 text-center text-sm text-[#8C8474]">
                    @if ($conversations->isEmpty())
                        Once a Messenger webhook arrives, conversations will appear in the list.
                    @else
                        Select a conversation to read and reply.
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
