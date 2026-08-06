@php
    $activeFilterCount = collect([$channel, $unread, $window, $linked])
        ->filter(fn (string $value) => $value !== '')
        ->count();
@endphp

<div class="xl:space-y-6">
    {{--
        Fixed beacon so Graph poll keeps running while the mobile thread sheet is open.
        The sheet is position:fixed (out of document flow), which can collapse in-flow
        layout and pause wire:poll.visible on the page root.
        When Echo realtime is on, Graph poll is slower (backfill only).
    --}}
    <div
        wire:poll.{{ $graphPollSeconds }}s.visible="pollSyncFromFacebook"
        class="pointer-events-none fixed bottom-0 left-0 z-[60] h-px w-px opacity-0"
        aria-hidden="true"
        data-inbox-realtime="{{ $realtimeEnabled ? '1' : '0' }}"
        data-graph-poll-seconds="{{ $graphPollSeconds }}"
    ></div>

    @script
    <script>
        if (window.Echo && @json($realtimeEnabled)) {
            window.Echo.private('admin.inbox').listen('.InboxMessageStored', (event) => {
                $wire.refreshFromRealtime(event.conversation_id ?? null);
            });
        }
    </script>
    @endscript

    @if ($syncToast)
        <div
            wire:key="sync-toast-{{ md5($syncToast) }}"
            x-data="{ show: true }"
            x-show="show"
            x-transition.opacity.duration.200ms
            x-init="setTimeout(() => { show = false; $wire.dismissSyncToast() }, 8000)"
            class="fixed bottom-4 left-1/2 z-[70] w-[min(24rem,calc(100%-2rem))] -translate-x-1/2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-900 shadow-lg"
            role="alert"
        >
            <div class="flex items-start gap-2">
                <p class="min-w-0 flex-1">Sync failed: {{ $syncToast }}</p>
                <button type="button"
                    wire:click="dismissSyncToast"
                    class="shrink-0 text-xs font-semibold text-rose-700 hover:text-rose-900"
                    aria-label="Dismiss sync error">
                    Dismiss
                </button>
            </div>
        </div>
    @endif

    <div class="px-4 pt-3 xl:px-0 xl:pt-0">
        {{-- Keep visible on mobile even with a thread open — expired tokens must stay reachable. --}}
        <livewire:admin.admin-facebook-token-gate />
    </div>

    {{-- Header: on small screens, Inbox title + icon actions share one row. --}}
    <div @class([
        'flex flex-col gap-2 px-4 pt-2 xl:gap-3 xl:px-0 xl:pt-0',
        'hidden xl:flex' => $mobileThreadOpen,
    ])>
        <div class="flex items-center justify-between gap-3 xl:items-end">
            <div class="min-w-0">
                <h1 class="font-serif text-2xl font-semibold xl:text-3xl">Inbox</h1>
                <p class="mt-0.5 hidden text-sm text-[#8C8474] xl:block">
                    Messenger conversations in one place.
                    @if ($realtimeEnabled)
                        Live updates when Reverb is running; Graph backfill every {{ $graphPollSeconds }}s.
                    @else
                        Syncs from Facebook every {{ $graphPollSeconds }}s while this page is open.
                    @endif
                </p>
                <p
                    class="mt-0.5 text-[11px] tabular-nums text-[#8C8474] xl:mt-1"
                    wire:key="auto-sync-countdown-{{ $lastSyncedAt ?? 'pending' }}-{{ $graphPollSeconds }}"
                    x-data="{
                        interval: {{ (int) $graphPollSeconds }},
                        remaining: {{ (int) $graphPollSeconds }},
                        lastAt: @js($lastSyncedAt),
                        timer: null,
                        label() {
                            if (this.remaining <= 0) {
                                return 'Auto syncing…';
                            }

                            return 'Auto sync in ' + this.remaining + 's';
                        },
                        refreshFromLastSync() {
                            if (! this.lastAt) {
                                return;
                            }

                            const nextAt = Date.parse(this.lastAt) + (this.interval * 1000);
                            if (Number.isNaN(nextAt)) {
                                return;
                            }

                            this.remaining = Math.max(0, Math.ceil((nextAt - Date.now()) / 1000));
                        },
                        init() {
                            this.refreshFromLastSync();
                            this.timer = setInterval(() => {
                                if (this.lastAt) {
                                    this.refreshFromLastSync();
                                } else {
                                    this.remaining = Math.max(0, this.remaining - 1);
                                }
                            }, 1000);
                        },
                        destroy() {
                            if (this.timer) {
                                clearInterval(this.timer);
                            }
                        },
                    }"
                >
                    <span x-text="label()">Auto sync in {{ (int) $graphPollSeconds }}s</span>
                </p>
                @if ($lastSyncError)
                    <p class="mt-0.5 text-[11px] text-rose-700">Last sync failed</p>
                @endif
            </div>

            <div class="flex shrink-0 items-center gap-1.5 xl:flex-col xl:items-end xl:gap-2">
                <div class="flex items-center gap-1.5 xl:gap-2">
                    <button type="button"
                        wire:click="syncFromFacebook"
                        wire:loading.attr="disabled"
                        wire:target="syncFromFacebook"
                        title="Sync Messenger"
                        aria-label="Sync Messenger"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227] disabled:opacity-60 xl:h-auto xl:w-auto xl:gap-2 xl:px-4 xl:py-2 xl:text-sm xl:font-semibold">
                        <svg wire:loading.remove wire:target="syncFromFacebook" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 xl:hidden" aria-hidden="true">
                            <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H4.39a.75.75 0 0 0-.75.75v3.842a.75.75 0 0 0 1.5 0v-2.14l.311.311a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm-10.623-2.85a5.5 5.5 0 0 1 9.201-2.466l.312.311H11.77a.75.75 0 0 0 0 1.5h3.843a.75.75 0 0 0 .75-.75V3.328a.75.75 0 1 0-1.5 0V5.47l-.311-.311A7 7 0 0 0 3.04 8.295a.75.75 0 1 0 1.45.39Z" clip-rule="evenodd" />
                        </svg>
                        <svg wire:loading wire:target="syncFromFacebook" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 animate-spin xl:hidden" aria-hidden="true">
                            <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H4.39a.75.75 0 0 0-.75.75v3.842a.75.75 0 0 0 1.5 0v-2.14l.311.311a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm-10.623-2.85a5.5 5.5 0 0 1 9.201-2.466l.312.311H11.77a.75.75 0 0 0 0 1.5h3.843a.75.75 0 0 0 .75-.75V3.328a.75.75 0 1 0-1.5 0V5.47l-.311-.311A7 7 0 0 0 3.04 8.295a.75.75 0 1 0 1.45.39Z" clip-rule="evenodd" />
                        </svg>
                        <span class="hidden xl:inline" wire:loading.remove wire:target="syncFromFacebook">Sync Messenger</span>
                        <span class="hidden xl:inline" wire:loading wire:target="syncFromFacebook">Syncing…</span>
                    </button>

                    <a href="{{ route('admin.inbox.quick-replies') }}" wire:navigate
                        title="Quick replies"
                        aria-label="Quick replies"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227] xl:h-auto xl:w-auto xl:px-4 xl:py-2 xl:text-sm xl:font-semibold">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 xl:hidden" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 3c-4.31 0-8 3.033-8 7 0 2.024.978 3.825 2.499 5.085a3.478 3.478 0 0 1-.522 1.756.75.75 0 0 0 .584 1.143 5.976 5.976 0 0 0 3.936-1.108c.542.142 1.112.22 1.703.22 4.31 0 8-3.033 8-7s-3.69-7-8-7Zm0 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm-2-1a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm5 1a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
                        </svg>
                        <span class="hidden xl:inline">Quick replies</span>
                    </a>

                    <button type="button"
                        wire:click="toggleMobileFilters"
                        aria-expanded="{{ $mobileFiltersOpen ? 'true' : 'false' }}"
                        aria-label="Filters"
                        title="Filters"
                        class="relative inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227] xl:hidden">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                            <path fill-rule="evenodd" d="M2.628 1.601C5.028 1.206 7.49 1 10 1s4.973.206 7.372.601a.75.75 0 0 1 .628.74v2.288a2.25 2.25 0 0 1-.659 1.59l-4.682 4.683a2.25 2.25 0 0 0-.659 1.59v3.037c0 .684-.31 1.33-.844 1.757l-1.937 1.55A.75.75 0 0 1 8 18.25v-5.757a2.25 2.25 0 0 0-.659-1.591L2.659 6.22A2.25 2.25 0 0 1 2 4.629V2.34a.75.75 0 0 1 .628-.74Z" clip-rule="evenodd" />
                        </svg>
                        @if ($activeFilterCount > 0)
                            <span class="absolute -right-1 -top-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-[#C9A227] px-1 text-[10px] font-bold text-white">
                                {{ $activeFilterCount }}
                            </span>
                        @endif
                    </button>
                </div>
            </div>
        </div>

        <div @class([
            'grid w-full grid-cols-2 gap-2 rounded-xl border border-[#EFE7D6] bg-white p-3 xl:flex xl:w-auto xl:flex-wrap xl:items-center xl:justify-end xl:gap-2 xl:rounded-none xl:border-0 xl:bg-transparent xl:p-0',
            'hidden' => ! $mobileFiltersOpen,
        ])>
            <select wire:model.live="channel" class="rounded-lg border border-[#E0D6C2] px-2.5 py-2 xl:px-3">
                <option value="">All channels</option>
                <option value="messenger">Messenger</option>
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

    @if ($error)
        <div @class([
            'mx-4 mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-900 xl:mx-0 xl:mt-0 xl:px-4 xl:py-3',
            'hidden xl:block' => $mobileThreadOpen,
        ])>{{ $error }}</div>
    @endif
    @if ($statusMessage)
        <div @class([
            'mx-4 mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-900 xl:mx-0 xl:mt-0 xl:px-4 xl:py-3',
            'hidden xl:block' => $mobileThreadOpen,
        ])>{{ $statusMessage }}</div>
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
            'mx-4 mt-3 rounded-xl border p-3 sm:p-4 xl:mx-0 xl:mt-0 xl:p-5',
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
                            Messenger webhook:
                            <code class="rounded bg-white/70 px-1.5 py-0.5">{{ $diagnostics['webhook_url'] }}</code>
                            (subscribe <code class="rounded bg-white/70 px-1.5 py-0.5">messages</code> +
                            <code class="rounded bg-white/70 px-1.5 py-0.5">standby</code>).
                            Use <strong>Sync Messenger</strong> for Graph backfill while the app can see those threads.
                        </p>
                    </details>
                    <p class="mt-2 hidden text-xs opacity-80 xl:block">
                        Messenger webhook:
                        <code class="rounded bg-white/70 px-1.5 py-0.5">{{ $diagnostics['webhook_url'] }}</code>
                        · Use <strong>Sync Messenger</strong> to import threads Graph can currently see.
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
                            <div class="break-words text-xs opacity-80">{{ $check['detail'] }}</div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($diagnostics['filters_active'] && $conversations->count() !== $diagnostics['total_conversations'])
        <div @class([
            'mx-4 mt-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950 xl:mx-0 xl:mt-0 xl:px-4 xl:py-3',
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

    <div @class([
        'mt-3 grid xl:mt-0 xl:grid-cols-[22rem_minmax(0,1fr)] xl:items-stretch xl:gap-6',
        'min-h-[calc(100dvh-8.5rem)] xl:min-h-0',
    ])>
        {{-- Conversation list --}}
        <div @class([
            'flex flex-col overflow-hidden bg-white',
            'border-y border-[#EFE7D6] xl:rounded-2xl xl:border',
            'h-[calc(100dvh-8.5rem)] max-h-[calc(100dvh-8.5rem)] xl:h-[min(75vh,52rem)] xl:max-h-[min(75vh,52rem)]',
            $mobileThreadOpen ? 'hidden xl:flex' : 'flex',
        ])>
            <div class="shrink-0 border-b border-[#E7DFCF] px-4 py-3 text-sm font-medium">
                Conversations
                <span class="ml-1 text-xs font-normal text-[#8C8474]">
                    ({{ $conversations->count() }} shown
                    @if ($diagnostics['total_conversations'] !== $conversations->count())
                        / {{ $diagnostics['total_conversations'] }} total
                    @endif
                    )
                </span>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto divide-y divide-[#F0E9DC]">
                @forelse ($conversations as $conversation)
                    @php
                        $selected = $selectedConversation?->id === $conversation->id;
                        $latest = $conversation->latestMessage;
                        $displayName = $conversation->customer_name ?: $conversation->customer_phone ?: $conversation->external_user_id;
                        $timestamp = optional($conversation->last_inbound_at ?: $conversation->last_outbound_at ?: $conversation->created_at)
                            ->timezone('Asia/Dhaka');
                    @endphp
                    <button type="button"
                        wire:key="inbox-conversation-{{ $conversation->id }}"
                        wire:click="selectConversation({{ $conversation->id }})"
                        wire:loading.class="opacity-60"
                        wire:target="selectConversation({{ $conversation->id }})"
                        @class([
                            'block w-full px-4 py-3 text-left transition',
                            $selected ? 'bg-[#FAF6EF]' : 'active:bg-[#FAF6EF] hover:bg-[#FAF6EF]/60',
                        ])>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p @class([
                                    'min-w-0 flex-1 truncate text-sm text-[#1E1E1E]',
                                    'font-semibold' => $conversation->isUnread(),
                                    'font-medium' => ! $conversation->isUnread(),
                                ])>
                                    {{ $displayName }}
                                </p>
                                <span class="shrink-0 text-[10px] tabular-nums text-[#8C8474]">
                                    {{ $timestamp?->format('d M h:i A') }}
                                </span>
                            </div>
                            <div class="mt-0.5 flex items-center gap-1.5">
                                @if ($conversation->isUnread())
                                    <span class="h-2 w-2 shrink-0 rounded-full bg-[#C9A227]" title="Unread" aria-label="Unread"></span>
                                @endif
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

        {{-- Thread: fullscreen messenger sheet on mobile --}}
        <div @class([
            'flex flex-col overflow-hidden bg-[#F7F3EA]',
            'fixed inset-0 z-30 xl:static xl:z-auto xl:rounded-2xl xl:border xl:border-[#EFE7D6] xl:bg-white',
            'xl:h-[min(75vh,52rem)] xl:max-h-[min(75vh,52rem)]',
            $mobileThreadOpen ? 'flex' : 'hidden xl:flex',
        ])>
            @if ($selectedConversation)
                <div class="shrink-0 border-b border-[#E7DFCF] bg-white/95 px-3 py-2.5 backdrop-blur xl:px-4 xl:py-3">
                    <div class="flex items-center gap-2">
                        <button type="button"
                            wire:click="closeMobileThread"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-[#6B6459] hover:bg-[#FAF6EF] xl:hidden"
                            aria-label="Back to conversations">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                                <path fill-rule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.56l3.22 3.22a.75.75 0 1 1-1.06 1.06l-4.5-4.5a.75.75 0 0 1 0-1.06l4.5-4.5a.75.75 0 0 1 1.06 1.06L5.56 9.25H16.25A.75.75 0 0 1 17 10Z" clip-rule="evenodd" />
                            </svg>
                        </button>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-base font-semibold text-[#1E1E1E]">
                                {{ $selectedConversation->customer_name ?: $selectedConversation->customer_phone ?: $selectedConversation->external_user_id }}
                            </h2>
                            <p class="mt-0.5 truncate text-[11px] text-[#8C8474]">
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
                                @if ($selectedConversation->needsMessengerSeenSync())
                                    ·
                                    <span title="{{ $messengerSeenPendingHint }}">
                                        Messenger seen pending
                                    </span>
                                @endif
                            </p>
                        </div>
                        <button type="button"
                            wire:click="toggleOrderPanel"
                            class="inline-flex h-10 shrink-0 items-center justify-center rounded-full border border-[#E0D6C2] bg-white px-3 text-xs font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]"
                            aria-label="Order fields"
                            title="Order fields">
                            {{ $selectedConversation->draftOrder ? 'Order' : '+ Order' }}
                        </button>
                        <button type="button"
                            wire:click="syncFromFacebook"
                            wire:loading.attr="disabled"
                            wire:target="syncFromFacebook"
                            class="inline-flex h-10 shrink-0 items-center justify-center rounded-full border border-[#E0D6C2] bg-white px-3 text-xs font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227] disabled:opacity-60 xl:hidden"
                            aria-label="Sync from Facebook">
                            <span wire:loading.remove wire:target="syncFromFacebook">Sync</span>
                            <span wire:loading wire:target="syncFromFacebook">…</span>
                        </button>
                    </div>
                    @if ($mappingField !== 'product')
                        @if ($hasOlderMessages && ! $threadHistoryExpanded)
                            <button type="button"
                                wire:click="loadOlderThreadHistory"
                                class="mt-1.5 text-[11px] font-medium text-[#8C8474] underline-offset-2 hover:text-[#C9A227] hover:underline">
                                load older messages
                            </button>
                        @elseif ($threadHistoryExpanded)
                            <p class="mt-1.5 text-[10px] text-[#8C8474]">Showing full conversation history</p>
                        @endif
                    @endif
                </div>

                @if ($orderPanelOpen && $selectedConversation->draftOrder)
                    @php $draft = $selectedConversation->draftOrder; @endphp
                    <div class="shrink-0 border-b border-[#E7DFCF] bg-[#FAF6EF] px-3 py-2.5 text-xs text-[#6B6459] xl:px-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 space-y-0.5">
                                <p class="font-semibold text-[#1E1E1E]">
                                    <a href="{{ route('admin.orders.show', $draft) }}" class="text-[#315AA9] hover:underline">
                                        {{ $draft->order_number }}
                                    </a>
                                    <span class="font-normal text-[#8C8474]">draft</span>
                                </p>
                                <p class="truncate">{{ $draft->name ?: '—' }} · {{ $draft->phone ?: '—' }}</p>
                                <p class="truncate">{{ $draft->address ?: 'No address yet' }}</p>
                                @if ($draft->items->isNotEmpty())
                                    <p class="truncate">
                                        {{ $draft->items->pluck('name')->filter()->take(2)->implode(', ') }}
                                        @if ($draft->items->count() > 2)
                                            +{{ $draft->items->count() - 2 }}
                                        @endif
                                    </p>
                                @endif
                            </div>
                            <a href="{{ route('admin.orders.edit', $draft) }}"
                                class="shrink-0 font-semibold text-[#C9A227] hover:underline">
                                Edit
                            </a>
                        </div>
                        <p class="mt-1.5 text-[10px] text-[#8C8474]">
                            Right-click or long-press a message → Add to order fields.
                        </p>
                    </div>
                @endif

                <div
                    wire:key="thread-{{ $selectedConversation->id }}"
                    x-data="{
                        stick: true,
                        threshold: 96,
                        init() {
                            this.scrollBottom();
                            const obs = new MutationObserver(() => {
                                if (this.stick) {
                                    this.$nextTick(() => this.scrollBottom());
                                }
                            });
                            obs.observe(this.$el, { childList: true, subtree: true });
                            this.$el.addEventListener('load', (e) => {
                                if (e.target.tagName === 'IMG' && this.stick) {
                                    this.scrollBottom();
                                }
                            }, true);
                        },
                        onScroll() {
                            const el = this.$el;
                            this.stick = el.scrollHeight - el.scrollTop - el.clientHeight < this.threshold;
                        },
                        scrollBottom() {
                            this.$el.scrollTop = this.$el.scrollHeight;
                        },
                    }"
                    @scroll.passive="onScroll()"
                    class="min-h-0 flex-1 space-y-2 overflow-y-auto px-3 py-3 xl:px-4 xl:py-4">
                    @foreach ($selectedConversation->messages as $messageRow)
                        @php $isOutbound = $messageRow->direction === 'outbound'; @endphp
                        <div @class([
                            'flex w-full',
                            'justify-end' => $isOutbound,
                            'justify-start' => ! $isOutbound,
                        ])>
                            <div
                                wire:key="inbox-msg-bubble-{{ $messageRow->id }}"
                                x-data="{
                                    menu: false,
                                    longPressTimer: null,
                                    touchStartX: 0,
                                    touchStartY: 0,
                                    openMenu() {
                                        if (! this.orderMapEnabled) {
                                            return;
                                        }
                                        this.cancelLongPress();
                                        this.menu = true;
                                        $wire.openMessageMapMenu({{ $messageRow->id }});
                                    },
                                    closeMenu() { this.menu = false; },
                                    startLongPress(event) {
                                        if (! this.orderMapEnabled) {
                                            return;
                                        }
                                        this.cancelLongPress();
                                        const touch = event.changedTouches?.[0] || event.touches?.[0];
                                        this.touchStartX = touch?.clientX ?? 0;
                                        this.touchStartY = touch?.clientY ?? 0;
                                        this.longPressTimer = setTimeout(() => this.openMenu(), 450);
                                    },
                                    cancelLongPress() {
                                        if (this.longPressTimer) {
                                            clearTimeout(this.longPressTimer);
                                            this.longPressTimer = null;
                                        }
                                    },
                                    onTouchMove(event) {
                                        const touch = event.touches?.[0];
                                        if (! touch || ! this.longPressTimer) {
                                            return;
                                        }
                                        const dx = Math.abs(touch.clientX - this.touchStartX);
                                        const dy = Math.abs(touch.clientY - this.touchStartY);
                                        if (dx > 10 || dy > 10) {
                                            this.cancelLongPress();
                                        }
                                    },
                                    orderMapEnabled: @js($isOutbound || ! $messageRow->isImageAttachment()),
                                    mapMenuOpen() {
                                        if (! this.orderMapEnabled) {
                                            return false;
                                        }
                                        const mappingId = Number($wire.mappingMessageId);
                                        const field = $wire.mappingField;
                                        return this.menu
                                            || (mappingId === {{ $messageRow->id }} && (field === null || field === '' || field === undefined));
                                    },
                                }"
                                @contextmenu.prevent="openMenu()"
                                @touchstart.passive="startLongPress($event)"
                                @touchend.passive="cancelLongPress()"
                                @touchmove.passive="onTouchMove($event)"
                                @click.outside="
                                    closeMenu();
                                    if (
                                        Number($wire.mappingMessageId) === {{ $messageRow->id }}
                                        && ! $wire.mappingField
                                    ) {
                                        $wire.closeMessageMapMenu();
                                    }
                                "
                                @class([
                                    'group relative max-w-[82%] px-3 py-2 text-sm shadow-sm sm:max-w-[70%]',
                                    'rounded-2xl rounded-br-md bg-[#C9A227] text-white' => $isOutbound,
                                    'rounded-2xl rounded-bl-md bg-white text-[#1E1E1E] ring-1 ring-[#EBE3D4]' => ! $isOutbound,
                                ])>
                                @if ($messageRow->replyTo)
                                    <div @class([
                                        'mb-2 rounded-xl px-2 py-1 text-[11px]',
                                        'bg-black/10 text-white/90' => $isOutbound,
                                        'border border-[#E7DFCF] bg-[#FAF6EF] text-[#6B6459]' => ! $isOutbound,
                                    ])>
                                        <span class="font-semibold">Replying to</span>
                                        <span class="block truncate">{{ $messageRow->replyTo->previewText() }}</span>
                                    </div>
                                @endif

                                @if (filled($messageRow->body))
                                    <p class="whitespace-pre-wrap break-words leading-relaxed">{{ $messageRow->body }}</p>
                                @endif
                                @if ($messageRow->isImageAttachment())
                                    {{-- Not an <a>: mobile long-press / right-click on links opens the browser menu instead of map fields. --}}
                                    <div
                                        class="relative mt-2 overflow-hidden rounded-xl {{ $isOutbound ? 'bg-black/10' : 'bg-[#FAF6EF]' }}"
                                        @contextmenu.prevent.stop="openMenu()"
                                        @touchstart.passive="startLongPress($event)"
                                        @touchend.passive="cancelLongPress()"
                                        @touchmove.passive="onTouchMove($event)"
                                    >
                                        <img
                                            src="{{ route('admin.inbox.media', $messageRow) }}"
                                            alt="Photo"
                                            class="max-h-72 w-full object-contain select-none"
                                            draggable="false"
                                            loading="lazy">
                                        @if (! $isOutbound)
                                            <div
                                                class="absolute right-2 top-2 z-20 flex gap-1.5"
                                                @click.stop
                                                @mousedown.stop
                                                @touchstart.stop
                                                @touchend.stop
                                                @contextmenu.stop.prevent
                                            >
                                                <button type="button"
                                                    wire:click.stop="openImageEdit({{ $messageRow->id }})"
                                                    title="Edit &amp; send"
                                                    aria-label="Edit image and send"
                                                    class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-black/55 text-white shadow hover:bg-black/70">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="pointer-events-none h-4 w-4" aria-hidden="true">
                                                        <path d="M2.695 14.763l-1.262 3.154a.5.5 0 00.65.65l3.155-1.262a4 4 0 001.343-.885L17.5 5.5a2.121 2.121 0 00-3-3L3.58 13.42a4 4 0 00-.885 1.343z"/>
                                                    </svg>
                                                </button>
                                                <button type="button"
                                                    wire:click.stop="openTagProductOnImage({{ $messageRow->id }})"
                                                    title="Find / tag product"
                                                    aria-label="Find and tag product"
                                                    class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-black/55 text-white shadow hover:bg-black/70">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="pointer-events-none h-4 w-4" aria-hidden="true">
                                                        <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd"/>
                                                    </svg>
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                    @if (! $isOutbound)
                                        @php
                                            $imageMatchState = $inboundImageMatchState[(string) $messageRow->id]
                                                ?? $inboundImageMatchState[$messageRow->id]
                                                ?? null;
                                            $taggedProductId = (int) ($messageRow->matched_product_id
                                                ?? ($imageMatchState['product_id'] ?? 0));
                                            $taggedProductName = $messageRow->matchedProduct?->name
                                                ?? ($imageMatchState['name'] ?? null);
                                            $taggedMatchPercent = isset($imageMatchState['match_percent'])
                                                ? (float) $imageMatchState['match_percent']
                                                : null;
                                        @endphp
                                        @if (($imageMatchState['status'] ?? null) === 'pending')
                                            <div class="mt-1.5 flex items-center gap-1.5 text-[10px] text-[#8C8474]"
                                                wire:key="inbound-image-match-loading-{{ $messageRow->id }}">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3 animate-spin" aria-hidden="true">
                                                    <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H4.39a.75.75 0 0 0-.75.75v3.842a.75.75 0 0 0 1.5 0v-2.14l.311.311a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm-10.623-2.85a5.5 5.5 0 0 1 9.201-2.466l.312.311H11.77a.75.75 0 0 0 0 1.5h3.843a.75.75 0 0 0 .75-.75V3.328a.75.75 0 1 0-1.5 0V5.47l-.311-.311A7 7 0 0 0 3.04 8.295a.75.75 0 1 0 1.45.39Z" clip-rule="evenodd" />
                                                </svg>
                                                <span>Matching product…</span>
                                            </div>
                                        @elseif ($taggedProductId > 0 && filled($taggedProductName))
                                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5"
                                                wire:key="inbound-image-tag-{{ $messageRow->id }}-{{ $taggedProductId }}">
                                                <a href="{{ route('admin.products.show', $taggedProductId) }}"
                                                    target="_blank"
                                                    rel="noopener"
                                                    @click.stop
                                                    class="inline-flex max-w-full items-center gap-1 rounded-full border border-[#E0D6C2] bg-[#FAF6EF] px-2 py-0.5 text-[10px] font-semibold text-[#1E1E1E] hover:border-[#C9A227] hover:bg-white"
                                                    title="{{ ($taggedMatchPercent !== null ? number_format($taggedMatchPercent, 1).'% match · ' : '').'Open product details' }}"
                                                    aria-label="Open product details for {{ $taggedProductName }}">
                                                    <span class="truncate">{{ $taggedProductName }}</span>
                                                    @if ($taggedMatchPercent !== null && ($imageMatchState['strategy'] ?? null) !== 'manual' && ($imageMatchState['strategy'] ?? null) !== 'stored')
                                                        <span class="shrink-0 tabular-nums text-[#8C8474]">{{ number_format($taggedMatchPercent, 0) }}%</span>
                                                    @endif
                                                </a>
                                                <button type="button"
                                                    wire:click="sendMatchedProductPriceReply({{ $messageRow->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="sendMatchedProductPriceReply({{ $messageRow->id }})"
                                                    @click.stop
                                                    title="Send price as a text reply"
                                                    class="text-[10px] font-semibold text-[#C9A227] hover:underline disabled:opacity-60">
                                                    Send price
                                                </button>
                                                <button type="button"
                                                    wire:click="sendPricedImageFromMatch({{ $messageRow->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="sendPricedImageFromMatch({{ $messageRow->id }})"
                                                    @click.stop
                                                    title="Send priced product image"
                                                    class="text-[10px] font-semibold text-[#C9A227] hover:underline disabled:opacity-60">
                                                    Send priced
                                                </button>
                                                <button type="button"
                                                    wire:click="addMatchedProductToOrder({{ $messageRow->id }})"
                                                    @click.stop
                                                    class="text-[10px] font-semibold text-[#C9A227] hover:underline">
                                                    Add to order
                                                </button>
                                                <button type="button"
                                                    wire:click="clearMatchedProduct({{ $messageRow->id }})"
                                                    @click.stop
                                                    class="text-[10px] font-medium text-[#8C8474] hover:text-[#6B6459]"
                                                    title="Clear product tag"
                                                    aria-label="Clear product tag">
                                                    Clear
                                                </button>
                                            </div>
                                        @endif
                                    @endif
                                @elseif ($messageRow->hasMedia())
                                    <a href="{{ route('admin.inbox.media', $messageRow) }}" target="_blank" rel="noopener"
                                        class="mt-1 inline-block text-xs {{ $isOutbound ? 'text-white/90 underline' : 'text-[#C9A227] hover:underline' }}">
                                        View attachment
                                    </a>
                                @endif
                                <div class="mt-1 flex items-center justify-end gap-2">
                                    @if ($messageRow->sent_at)
                                        <p @class([
                                            'text-[10px] tabular-nums',
                                            'text-white/75' => $isOutbound,
                                            'text-[#8C8474]' => ! $isOutbound,
                                        ])>
                                            {{ $messageRow->sent_at->timezone('Asia/Dhaka')->format('h:i A') }}
                                        </p>
                                    @endif
                                    <button type="button"
                                        wire:click="setReplyTo({{ $messageRow->id }})"
                                        @class([
                                            'text-[10px] font-medium opacity-100 transition xl:opacity-0 xl:group-hover:opacity-100 xl:focus:opacity-100',
                                            'text-white/90' => $isOutbound,
                                            'text-[#C9A227]' => ! $isOutbound,
                                        ])>
                                        Reply
                                    </button>
                                    @if ($isOutbound || ! $messageRow->isImageAttachment())
                                        <button type="button"
                                            @click.stop="openMenu()"
                                            @class([
                                                'text-[10px] font-medium opacity-100 transition xl:opacity-0 xl:group-hover:opacity-100 xl:focus:opacity-100',
                                                'text-white/90' => $isOutbound,
                                                'text-[#C9A227]' => ! $isOutbound,
                                            ])>
                                            + Order
                                        </button>
                                    @endif
                                </div>

                                @if ($isOutbound || ! $messageRow->isImageAttachment())
                                    <div
                                        x-show="mapMenuOpen()"
                                        x-cloak
                                        @click.stop
                                        class="absolute left-0 right-0 top-full z-20 mt-1 min-w-[11rem] rounded-xl border border-[#E7DFCF] bg-white p-1.5 text-left text-[#1E1E1E] shadow-lg"
                                    >
                                        <p class="px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-[#8C8474]">
                                            Add to order fields
                                        </p>
                                        @foreach (['phone' => 'Phone', 'name' => 'Name', 'address' => 'Address', 'product' => 'Products'] as $fieldKey => $fieldLabel)
                                            <button type="button"
                                                wire:click="beginMapField('{{ $fieldKey }}')"
                                                class="block w-full rounded-lg px-2 py-1.5 text-left text-xs font-medium hover:bg-[#FAF6EF]">
                                                {{ $fieldLabel }}
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    @if ($outboundSending)
                        <div class="flex w-full justify-end" wire:key="pending-outbound">
                            <div class="max-w-[82%] rounded-2xl rounded-br-md bg-[#C9A227]/80 px-3 py-2 text-sm text-white shadow-sm sm:max-w-[70%]">
                                @if (filled($pendingReplyText))
                                    <p class="whitespace-pre-wrap break-words leading-relaxed">{{ $pendingReplyText }}</p>
                                @endif
                                @if ($pendingReplyImage)
                                    <img src="{{ $pendingReplyImage->temporaryUrl() }}" alt="" class="mt-2 max-h-48 rounded-xl object-contain">
                                @endif
                                <p class="mt-1 text-[10px] font-medium text-white/80">Sending…</p>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="shrink-0 border-t border-[#E7DFCF] bg-white px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-2.5 xl:px-4 xl:py-3">
                    @if ($error)
                        <p class="mb-2 text-xs text-rose-600">{{ $error }}</p>
                    @endif
                    @if ($statusMessage)
                        <p class="mb-2 text-xs {{ $outboundSending ? 'text-[#8C8474]' : 'text-emerald-700' }}">{{ $statusMessage }}</p>
                    @endif

                    @if ($replyToMessage)
                        <div class="mb-2 flex items-start justify-between gap-3 rounded-2xl border border-[#E7DFCF] bg-[#FAF6EF] px-3 py-2">
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
                        <div class="mb-2 flex items-center gap-3 rounded-2xl border border-[#E7DFCF] bg-[#FAF6EF] px-3 py-2">
                            <img src="{{ $replyImage->temporaryUrl() }}" alt="" class="h-12 w-12 rounded-xl object-cover">
                            <div class="min-w-0 flex-1 text-xs text-[#6B6459]">Image ready to send</div>
                            <button type="button" wire:click="clearReplyImage" class="text-xs text-[#8C8474] hover:text-[#1E1E1E]">
                                Remove
                            </button>
                        </div>
                    @endif
                    @error('replyImage')
                        <p class="mb-2 text-xs text-rose-600">{{ $message }}</p>
                    @enderror

                    @if (! empty($quickReplies))
                        <div class="mb-2 flex items-center gap-1.5 overflow-x-auto pb-0.5">
                            @foreach ($quickReplies as $index => $quickReply)
                                <button type="button"
                                    wire:click="insertQuickReply({{ $index }})"
                                    class="shrink-0 rounded-full border border-[#E0D6C2] bg-[#FAF6EF] px-2.5 py-1 text-[11px] font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
                                    {{ $quickReply['label'] }}
                                </button>
                            @endforeach
                            <a href="{{ route('admin.inbox.quick-replies') }}" wire:navigate
                                class="shrink-0 px-1 text-[11px] font-semibold text-[#8C8474] hover:text-[#C9A227]">
                                Edit
                            </a>
                        </div>
                    @else
                        <div class="mb-2">
                            <a href="{{ route('admin.inbox.quick-replies') }}" wire:navigate
                                class="text-[11px] font-semibold text-[#C9A227] hover:underline">
                                Add quick replies
                            </a>
                        </div>
                    @endif

                    <div class="flex items-end gap-2">
                        <label class="inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full border border-[#E0D6C2] bg-[#FAF6EF] text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]" title="Attach image">
                            <input type="file" class="hidden" wire:model="replyImage" accept="image/*">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                                <path fill-rule="evenodd" d="M1 5.25A2.25 2.25 0 0 1 3.25 3h13.5A2.25 2.25 0 0 1 19 5.25v9.5A2.25 2.25 0 0 1 16.75 17H3.25A2.25 2.25 0 0 1 1 14.75v-9.5Zm4.78 1.47a.75.75 0 0 0-1.06 1.06l2.5 2.5a.75.75 0 0 0 1.06 0l1.72-1.72 3.22 3.22a.75.75 0 1 0 1.06-1.06l-3.75-3.75a.75.75 0 0 0-1.06 0L7.28 8.72 5.78 7.22Z" clip-rule="evenodd" />
                            </svg>
                        </label>
                        <div class="min-w-0 flex-1">
                            <div wire:loading wire:target="replyImage" class="mb-1 text-[11px] text-[#8C8474]">Uploading image…</div>
                            @php
                                $replyRows = max(1, min(8, substr_count((string) $replyText, "\n") + 1));
                            @endphp
                            <textarea
                                wire:model="replyText"
                                rows="{{ $replyRows }}"
                                placeholder="{{ $replyToMessage ? 'Write a reply…' : 'Message…' }}"
                                class="w-full resize-none rounded-2xl border border-[#E0D6C2] bg-[#FAF6EF] px-4 py-2.5 text-sm leading-5 focus:border-[#C9A227] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#C9A227] disabled:opacity-60"
                                wire:keydown.enter.exact.prevent="sendReply"
                                @disabled($outboundSending)
                                title="Enter to send · Shift+Enter for a new line"
                            ></textarea>
                        </div>
                        <button type="button"
                            wire:click="sendReply"
                            wire:loading.attr="disabled"
                            wire:target="sendReply,flushPendingOutbound"
                            @disabled($outboundSending)
                            class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#C9A227] text-white hover:bg-[#b89220] disabled:opacity-60"
                            aria-label="Send">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                                <path d="M3.105 2.288a.75.75 0 0 0-.826.95l1.414 4.926A1.5 1.5 0 0 0 5.135 9.25h6.115a.75.75 0 0 1 0 1.5H5.135a1.5 1.5 0 0 0-1.442 1.086l-1.414 4.926a.75.75 0 0 0 .826.95 28.897 28.897 0 0 0 15.293-7.154.75.75 0 0 0 0-1.115A28.897 28.897 0 0 0 3.105 2.288Z" />
                            </svg>
                        </button>
                    </div>
                </div>
            @else
                <div class="flex flex-1 items-center justify-center bg-white px-4 py-16 text-center text-sm text-[#8C8474] xl:rounded-2xl">
                    @if ($conversations->isEmpty())
                        Once a Messenger webhook arrives, conversations will appear in the list.
                    @else
                        Select a conversation to read and reply.
                    @endif
                </div>
            @endif
        </div>
    </div>

    @if ($mappingField === 'product' && $mappingMessage)
        {{-- x-data required so Livewire @teleport (Alpine x-teleport) actually mounts to body --}}
        <div x-data="{}" wire:key="product-map-teleport-{{ $mappingMessage->id }}">
            @teleport('body')
                <div
                    data-inbox-product-map-modal
                    wire:key="product-map-modal-{{ $mappingMessage->id }}"
                    wire:click.self="closeMessageMapMenu"
                    class="fixed inset-0 flex items-end justify-center overflow-y-auto overscroll-contain bg-black/50 p-0 sm:items-center sm:p-4"
                    style="z-index: 100000;"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="inbox-product-map-title"
                >
                    <div
                        class="relative flex w-full max-w-xl flex-col overflow-hidden rounded-t-2xl border border-[#E7DFCF] bg-white shadow-2xl sm:rounded-2xl"
                        style="max-height: calc(100vh - 1rem); max-height: calc(100dvh - 1rem); max-height: calc(100svh - 1rem); min-height: 0;"
                        @click.stop
                        @mousedown.stop
                    >
                        <div class="flex shrink-0 items-start justify-between gap-3 border-b border-[#E7DFCF] px-4 py-3">
                            <div class="min-w-0">
                                <h3 id="inbox-product-map-title" class="text-base font-semibold text-[#1E1E1E]">
                                    {{ $mappingMode === 'tag' ? 'Tag product on photo' : 'Add product to order' }}
                                </h3>
                                <p class="mt-0.5 text-xs text-[#8C8474]">
                                    @if ($mappingMode === 'tag')
                                        Search the catalog{{ $mappingMessage->isImageAttachment() ? ', or crop the chat image to match' : '' }}. You can send a priced image or add it to the order after tagging.
                                    @else
                                        Search the catalog{{ $mappingMessage->isImageAttachment() ? ', or crop the chat image to match' : '' }}.
                                    @endif
                                </p>
                            </div>
                            <button type="button" wire:click="closeMessageMapMenu" class="text-2xl leading-none text-[#8C8474] hover:text-[#1E1E1E]" aria-label="Close">&times;</button>
                        </div>

                        <div class="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain p-4" style="-webkit-overflow-scrolling: touch;">
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Search products</label>
                                <input type="search"
                                    wire:model.live.debounce.250ms="mappingProductSearch"
                                    placeholder="Name, SKU, or price…"
                                    class="w-full rounded-xl border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"
                                    aria-label="Search products">
                                <div class="mt-2 max-h-36 space-y-1.5 overflow-y-auto overscroll-contain sm:max-h-40">
                                    @forelse ($mappingProductSuggestions as $suggestion)
                                        <button type="button"
                                            wire:click="chooseMappingProduct({{ $suggestion['id'] }})"
                                            class="flex w-full items-center gap-2.5 rounded-xl border border-[#EFE7D6] px-2.5 py-2 text-left hover:border-[#C9A227] hover:bg-[#FAF6EF]">
                                            @if (! empty($suggestion['image_url']))
                                                <img src="{{ $suggestion['image_url'] }}" alt="" class="h-11 w-11 shrink-0 rounded-lg object-cover bg-[#FAF6EF]">
                                            @else
                                                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-[#E7DFCF] bg-[#FAF6EF] text-[10px] text-[#8C8474]">No img</div>
                                            @endif
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-medium text-[#1E1E1E]">{{ $suggestion['name'] }}</p>
                                                <p class="truncate text-[11px] text-[#8C8474]">
                                                    {{ $suggestion['sku'] ?? '—' }}
                                                    · ৳{{ number_format($suggestion['price']) }}
                                                    @if (isset($suggestion['stock_quantity']))
                                                        · Stock {{ $suggestion['stock_quantity'] }}
                                                    @endif
                                                </p>
                                            </div>
                                        </button>
                                    @empty
                                        @if (trim($mappingProductSearch) !== '')
                                            <p class="text-xs text-[#8C8474]">No products match that search.</p>
                                        @elseif ($mappingMode !== 'tag' && ! $mappingMessage->isImageAttachment())
                                            <button type="button"
                                                wire:click="applyMapField('product')"
                                                class="w-full rounded-xl border border-dashed border-[#E0D6C2] px-3 py-2 text-left text-xs text-[#6B6459] hover:border-[#C9A227]">
                                                Use message text as unmatched product
                                            </button>
                                        @endif
                                    @endforelse
                                </div>
                            </div>

                            @if ($mappingMessage->isImageAttachment())
                                <div
                                    wire:ignore
                                    x-data="inboxProductCrop(@js(route('admin.inbox.media', $mappingMessage)))"
                                    class="rounded-xl border border-[#E7DFCF] bg-[#FAF6EF] p-3"
                                >
                                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Crop chat image</p>
                                    <div
                                        class="relative overflow-hidden rounded-lg bg-black/5"
                                        style="height: min(52vh, 26rem); max-height: min(52vh, 26rem);"
                                    >
                                        <img
                                            x-ref="cropImage"
                                            src="{{ route('admin.inbox.media', $mappingMessage) }}"
                                            alt="Chat image"
                                            class="block h-full w-full object-contain"
                                        >
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <button type="button" @click="rotate(-90)" class="rounded-full border border-[#E0D6C2] bg-white px-2.5 py-1 text-[11px] font-semibold text-[#6B6459]">↺</button>
                                        <button type="button" @click="rotate(90)" class="rounded-full border border-[#E0D6C2] bg-white px-2.5 py-1 text-[11px] font-semibold text-[#6B6459]">↻</button>
                                        <button type="button" @click="resetCrop()" class="rounded-full border border-[#E0D6C2] bg-white px-2.5 py-1 text-[11px] font-semibold text-[#6B6459]">Reset</button>
                                        <button type="button"
                                            @click="findMatch()"
                                            :disabled="busy"
                                            class="ml-auto rounded-full bg-[#C9A227] px-3 py-1 text-[11px] font-semibold text-white hover:bg-[#b89220] disabled:opacity-60">
                                            <span x-show="!busy">Find match</span>
                                            <span x-show="busy" x-cloak>Matching…</span>
                                        </button>
                                    </div>
                                    <p x-show="error" x-text="error" class="mt-2 text-xs text-rose-600" x-cloak></p>
                                </div>
                            @endif

                            @if ($mappingImageMatchError)
                                <p class="text-xs text-rose-600">{{ $mappingImageMatchError }}</p>
                            @endif

                            @if ($mappingImageMatches !== [])
                                <div class="space-y-2">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Image matches</p>
                                    @foreach ($mappingImageMatches as $match)
                                        <div wire:key="inbox-image-match-{{ $match['product_id'] }}" class="flex items-center gap-2.5 rounded-xl border border-[#E7DFCF] p-2.5">
                                            @if ($match['image_url'])
                                                <img src="{{ $match['image_url'] }}" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover bg-[#FAF6EF]">
                                            @else
                                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-[#E7DFCF] bg-[#FAF6EF] text-[10px] text-[#8C8474]">No img</div>
                                            @endif
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-medium">{{ $match['name'] }}</p>
                                                <p class="text-[11px] text-[#8C8474]">
                                                    {{ $match['sku'] ?: '—' }} · ৳{{ number_format($match['price']) }}
                                                </p>
                                                <p class="text-[11px] font-semibold text-emerald-700">{{ number_format($match['match_percent'], 1) }}% match</p>
                                            </div>
                                            <button type="button"
                                                wire:click="selectMappingImageMatch({{ $match['product_id'] }})"
                                                class="shrink-0 rounded-lg bg-[#C9A227] px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-[#b89220]">
                                                {{ $mappingMode === 'tag' ? 'Tag' : 'Add' }}
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endteleport
        </div>
    @endif

    @if ($imageEditMessage && $imageEditMessage->isImageAttachment())
        <div x-data="{}" wire:key="image-edit-teleport-{{ $imageEditMessage->id }}">
            @teleport('body')
                <div
                    data-inbox-image-edit-modal
                    wire:key="image-edit-modal-{{ $imageEditMessage->id }}"
                    wire:click.self="closeImageEdit"
                    class="fixed inset-0 flex items-end justify-center overflow-y-auto overscroll-contain bg-black/50 p-0 sm:items-center sm:p-4"
                    style="z-index: 100000;"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="inbox-image-edit-title"
                >
                    <div
                        class="relative flex w-full max-w-3xl flex-col overflow-hidden rounded-t-2xl border border-[#E7DFCF] bg-white shadow-2xl sm:rounded-2xl"
                        style="max-height: calc(100vh - 1rem); max-height: calc(100dvh - 1rem); max-height: calc(100svh - 1rem); min-height: 0;"
                        @click.stop
                        @mousedown.stop
                        wire:ignore.self
                        x-data="inboxImageEdit(@js(route('admin.inbox.media', $imageEditMessage)), @js($this->getId()))"
                    >
                        <div class="flex shrink-0 items-start justify-between gap-3 border-b border-[#E7DFCF] px-4 py-3">
                            <div class="min-w-0">
                                <h3 id="inbox-image-edit-title" class="text-base font-semibold text-[#1E1E1E]">Edit &amp; send image</h3>
                                <p class="mt-0.5 text-xs text-[#8C8474]">Crop, add text, then send back to the customer.</p>
                            </div>
                            <button type="button" wire:click="closeImageEdit" class="text-2xl leading-none text-[#8C8474] hover:text-[#1E1E1E]" aria-label="Close">&times;</button>
                        </div>

                        <div class="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain p-4" style="-webkit-overflow-scrolling: touch;">
                            <div
                                class="relative overflow-hidden rounded-lg bg-black/5"
                                style="height: min(42vh, 22rem); max-height: min(42vh, 22rem);"
                            >
                                <img
                                    x-ref="editImage"
                                    src="{{ route('admin.inbox.media', $imageEditMessage) }}"
                                    alt="Customer image"
                                    class="block h-full w-full object-contain"
                                >
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <button type="button" @click="rotate(-90)" class="rounded-full border border-[#E0D6C2] bg-white px-2.5 py-1 text-[11px] font-semibold text-[#6B6459]">Rotate left</button>
                                <button type="button" @click="rotate(90)" class="rounded-full border border-[#E0D6C2] bg-white px-2.5 py-1 text-[11px] font-semibold text-[#6B6459]">Rotate right</button>
                                <button type="button" @click="resetCrop()" class="rounded-full border border-[#E0D6C2] bg-white px-2.5 py-1 text-[11px] font-semibold text-[#6B6459]">Reset crop</button>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Text on image</label>
                                    <input type="text"
                                        x-model="overlayText"
                                        maxlength="80"
                                        placeholder="e.g. Available · Tk 2500"
                                        class="w-full rounded-xl border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Text size</label>
                                    <input type="range" min="24" max="96" step="2" x-model.number="overlayTextSize" class="w-full">
                                </div>
                            </div>

                            <div class="flex gap-1.5" role="group" aria-label="Text position">
                                @foreach ([
                                    'top-left' => 'Top left',
                                    'top-right' => 'Top right',
                                    'bottom-left' => 'Bottom left',
                                    'bottom-right' => 'Bottom right',
                                    'center' => 'Center',
                                ] as $value => $label)
                                    <button type="button"
                                        @click="overlayTextPosition = '{{ $value }}'"
                                        title="{{ $label }}"
                                        aria-label="{{ $label }}"
                                        :aria-pressed="overlayTextPosition === '{{ $value }}' ? 'true' : 'false'"
                                        :class="overlayTextPosition === '{{ $value }}'
                                            ? 'border-[#1E1E1E] bg-[#1E1E1E] text-white'
                                            : 'border-[#E0D6C2] bg-white text-[#1E1E1E] hover:bg-[#FAF6EF]'"
                                        class="inline-flex h-9 flex-1 items-center justify-center rounded-lg border transition">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                            <rect x="2.5" y="2.5" width="15" height="15" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.45"/>
                                            @switch($value)
                                                @case('top-left')
                                                    <rect x="4" y="4" width="5.5" height="4" rx="0.75"/>
                                                    @break
                                                @case('top-right')
                                                    <rect x="10.5" y="4" width="5.5" height="4" rx="0.75"/>
                                                    @break
                                                @case('bottom-left')
                                                    <rect x="4" y="12" width="5.5" height="4" rx="0.75"/>
                                                    @break
                                                @case('bottom-right')
                                                    <rect x="10.5" y="12" width="5.5" height="4" rx="0.75"/>
                                                    @break
                                                @default
                                                    <rect x="6.5" y="7.5" width="7" height="5" rx="0.75"/>
                                            @endswitch
                                        </svg>
                                        <span class="sr-only">{{ $label }}</span>
                                    </button>
                                @endforeach
                            </div>

                            <div x-show="previewUrl" x-cloak class="rounded-xl border border-[#E7DFCF] bg-[#FAF6EF] p-2">
                                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Preview</p>
                                <img :src="previewUrl" alt="Edited preview" class="mx-auto max-h-40 rounded-lg object-contain">
                            </div>

                            <p x-show="error" x-text="error" class="text-xs text-rose-600" x-cloak></p>
                        </div>

                        <div class="flex shrink-0 items-center justify-end gap-2 border-t border-[#E7DFCF] px-4 py-3">
                            <button type="button" wire:click="closeImageEdit" class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm font-semibold text-[#6B6459]">Cancel</button>
                            <button type="button"
                                @click="sendEdited()"
                                :disabled="busy"
                                class="rounded-full bg-[#C9A227] px-4 py-2 text-sm font-semibold text-white hover:bg-[#b89220] disabled:opacity-60">
                                <span x-show="!busy">Send to customer</span>
                                <span x-show="busy" x-cloak>Sending…</span>
                            </button>
                        </div>
                    </div>
                </div>
            @endteleport
        </div>
    @endif

    @if ($pricedSendMessage)
        <div x-data="{}" wire:key="priced-send-teleport-{{ $pricedSendMessage->id }}">
            @teleport('body')
                <div
                    data-inbox-priced-send-modal
                    wire:key="priced-send-modal-{{ $pricedSendMessage->id }}"
                    wire:click.self="closePricedImageSend"
                    class="fixed inset-0 flex items-end justify-center overflow-y-auto overscroll-contain bg-black/50 p-0 sm:items-center sm:p-4"
                    style="z-index: 100000;"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="inbox-priced-send-title"
                >
                    <div
                        class="relative flex w-full max-w-xl flex-col overflow-hidden rounded-t-2xl border border-[#E7DFCF] bg-white shadow-2xl sm:rounded-2xl"
                        style="max-height: calc(100vh - 1rem); max-height: calc(100dvh - 1rem); max-height: calc(100svh - 1rem); min-height: 0;"
                        @click.stop
                        @mousedown.stop
                    >
                        <div class="flex shrink-0 items-start justify-between gap-3 border-b border-[#E7DFCF] px-4 py-3">
                            <div class="min-w-0">
                                <h3 id="inbox-priced-send-title" class="text-base font-semibold text-[#1E1E1E]">Send priced product image</h3>
                                <p class="mt-0.5 text-xs text-[#8C8474]">Filter by category or price, then send a priced image as a reply.</p>
                            </div>
                            <button type="button" wire:click="closePricedImageSend" class="text-2xl leading-none text-[#8C8474] hover:text-[#1E1E1E]" aria-label="Close">&times;</button>
                        </div>

                        <div class="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain p-4" style="-webkit-overflow-scrolling: touch;">
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Search</label>
                                <input type="search"
                                    wire:model.live.debounce.250ms="pricedSendSearch"
                                    placeholder="Name or SKU…"
                                    class="w-full rounded-xl border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"
                                    aria-label="Search products">
                            </div>

                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Category</label>
                                    <select wire:model.live="pricedSendCategory"
                                        class="w-full rounded-xl border border-[#E0D6C2] bg-white px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                        <option value="">All</option>
                                        @foreach ($pricedSendCategories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Min price</label>
                                    <input type="text"
                                        wire:model.live.debounce.250ms="pricedSendPriceMin"
                                        inputmode="numeric"
                                        placeholder="0"
                                        class="w-full rounded-xl border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Max price</label>
                                    <input type="text"
                                        wire:model.live.debounce.250ms="pricedSendPriceMax"
                                        inputmode="numeric"
                                        placeholder="Any"
                                        class="w-full rounded-xl border border-[#E0D6C2] px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                </div>
                            </div>

                            <div class="space-y-2">
                                @forelse ($pricedSendResults as $row)
                                    <div wire:key="priced-send-row-{{ $row['id'] }}" class="flex items-center gap-2.5 rounded-xl border border-[#E7DFCF] p-2.5">
                                        @if (! empty($row['image_url']))
                                            <img src="{{ $row['image_url'] }}" alt="" class="h-14 w-14 shrink-0 rounded-lg object-cover bg-[#FAF6EF]">
                                        @else
                                            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg border border-[#E7DFCF] bg-[#FAF6EF] text-[10px] text-[#8C8474]">No img</div>
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-[#1E1E1E]">{{ $row['name'] }}</p>
                                            <p class="truncate text-[11px] text-[#8C8474]">
                                                {{ $row['category'] ?? '—' }}
                                                · ৳{{ number_format($row['price']) }}
                                                @if ($row['sku'])
                                                    · {{ $row['sku'] }}
                                                @endif
                                            </p>
                                            <p class="text-[11px] {{ $row['has_priced_image'] ? 'text-emerald-700' : 'text-[#8C8474]' }}">
                                                {{ $row['has_priced_image'] ? 'Priced image ready' : 'Will generate priced image' }}
                                            </p>
                                        </div>
                                        <button type="button"
                                            wire:click="sendPricedProductImage({{ $row['id'] }})"
                                            wire:loading.attr="disabled"
                                            class="shrink-0 rounded-lg bg-[#C9A227] px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-[#b89220] disabled:opacity-60">
                                            Send
                                        </button>
                                    </div>
                                @empty
                                    <p class="text-xs text-[#8C8474]">No products match these filters.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>
            @endteleport
        </div>
    @endif
</div>
