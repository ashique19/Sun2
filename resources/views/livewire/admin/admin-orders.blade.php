<div wire:key="admin-orders-{{ $segment }}-{{ $listRevision }}" @class(['pb-28 sm:pb-20' => ! $readOnly && $selectedCount > 0])>
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <h1 class="font-serif text-3xl font-semibold">{{ $segmentLabel }} Orders</h1>
        <div class="flex flex-wrap items-center gap-2">
            @if ($segment === 'dispatched' && $courierApiAvailable && ! $readOnly)
                <button type="button"
                    wire:click="refreshCourierStatuses"
                    wire:loading.attr="disabled"
                    class="rounded-lg border border-[#E0D6C2] bg-white px-4 py-2 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF] disabled:opacity-60">
                    <span wire:loading.remove wire:target="refreshCourierStatuses">Refresh tracking</span>
                    <span wire:loading wire:target="refreshCourierStatuses">Refreshing…</span>
                </button>
            @endif
            @unless ($readOnly)
                <a href="{{ route('admin.orders.create') }}"
                    class="rounded-lg bg-[#C9A227] px-4 py-2 text-sm font-medium text-white hover:bg-[#b89220] transition">
                    Create order
                </a>
            @endunless
        </div>
    </div>

    @unless ($readOnly)
        <div class="flex flex-wrap gap-2 mb-6">
            @foreach ($segments as $segmentKey => $segmentName)
                <button type="button"
                    wire:key="segment-tab-{{ $segmentKey }}"
                    wire:click="switchSegment('{{ $segmentKey }}')"
                    wire:loading.attr="disabled"
                    class="rounded-full px-4 py-1.5 text-sm border transition disabled:opacity-60 {{ $segment === $segmentKey ? 'border-[#C9A227] bg-[#C9A227] text-white font-medium' : 'border-[#E0D6C2] bg-white text-[#6B6459] hover:bg-[#FAF6EF]' }}">
                    {{ $segmentName }}
                </button>
            @endforeach
        </div>
    @endunless

    @unless ($readOnly)
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search order #, name, phone…"
                class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
        </div>
    @endunless

    @if ($readOnly)
        <div class="space-y-3" wire:loading.class="opacity-60" wire:target="switchSegment,search,nextPage,previousPage,gotoPage" wire:key="moderator-orders-{{ $segment }}-{{ $listRevision }}">
            @php($lastGroupKey = null)
            @forelse ($orders as $order)
                @php($adminNote = filled($order->admin_note) ? \Illuminate\Support\Str::of($order->admin_note)->replace(['<br />', '<br/>', '<br>'], "\n")->stripTags()->trim() : null)
                @php($courierNote = filled($order->courier_note) ? \Illuminate\Support\Str::of($order->courier_note)->replace(['<br />', '<br/>', '<br>'], "\n")->stripTags()->trim() : null)
                @php($groupDate = $order->placed_at)
                @php($groupKey = $groupDate?->timezone('Asia/Dhaka')->toDateString() ?? '_none')
                @if ($groupKey !== $lastGroupKey)
                    <x-admin.order-date-heading
                        :date="$groupDate"
                        kind="order"
                        :count="$dateGroupCounts[$groupKey] ?? null"
                    />
                    @php($lastGroupKey = $groupKey)
                @endif
                <article wire:key="order-card-{{ $order->id }}" class="rounded-xl border border-[#EFE7D6] bg-white p-4">
                    <div class="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
                        <div class="min-w-0 sm:flex-1">
                            <div class="font-medium text-[#1E1E1E]">{{ $order->name }}</div>
                            <div class="text-sm text-[#8C8474]">{{ $order->phone }}</div>
                            <div class="mt-0.5 text-xs text-[#8C8474]">Created by {{ $order->createdByLabel() }}</div>
                            @php($areaCity = collect([$order->area, $order->city])->filter()->implode(', '))
                            @if ($areaCity !== '' || filled($order->address))
                                <div x-data="{ open: false }" class="mt-1">
                                    <button type="button"
                                        @click="open = ! open"
                                        class="text-left text-sm leading-relaxed text-[#6B6459] hover:text-[#C9A227]">
                                        {{ $areaCity !== '' ? $areaCity : 'Show address' }}
                                    </button>
                                    @if (filled($order->address))
                                        <p x-show="open" x-cloak class="mt-1 text-sm leading-relaxed text-[#1E1E1E] break-words">{{ $order->address }}</p>
                                    @endif
                                </div>
                            @endif
                            @if ($order->is_replacement)
                                <span title="Exchange order"
                                    class="mt-1 inline-flex items-center rounded border border-sky-200 bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">
                                    Exc
                                </span>
                            @endif
                        </div>
                        <div class="grid w-full grid-cols-2 gap-3 sm:w-auto sm:min-w-[14rem] sm:flex-1 sm:grid-cols-2 md:grid-cols-3">
                            @forelse ($order->items as $item)
                                <x-order-product-thumb
                                    :item="$item"
                                    size="md"
                                    show-quantity
                                />
                            @empty
                                <span class="col-span-full text-sm text-[#8C8474]">—</span>
                            @endforelse
                        </div>
                        @php($netRevenue = $order->netRevenue())
                        <div class="shrink-0 text-right sm:min-w-[5.5rem]">
                            <p class="text-[11px] uppercase tracking-wide text-[#8C8474]">COD</p>
                            <p class="text-sm font-semibold tabular-nums text-[#1E1E1E]">&#2547; {{ number_format($order->total, 0) }}</p>
                            <p class="mt-1 text-[11px] text-[#8C8474]">Net
                                <span @class(['tabular-nums font-medium', 'text-rose-600' => $netRevenue < 0, 'text-[#6B6459]' => $netRevenue >= 0])>&#2547;{{ number_format($netRevenue, 0) }}</span>
                            </p>
                        </div>
                    </div>
                    @if ($adminNote || $courierNote)
                        <div class="mt-3 space-y-2 border-t border-[#EFE7D6] pt-3">
                            @if ($adminNote)
                                <div class="rounded-lg border-2 border-rose-500 bg-rose-50 px-3 py-2.5">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-rose-700">Admin</p>
                                    <p class="mt-1 text-sm font-medium leading-relaxed text-rose-800 whitespace-pre-line break-words">{{ $adminNote }}</p>
                                </div>
                            @endif
                            @if ($courierNote)
                                <div class="rounded-lg border border-[#E7DFCF] bg-[#FAF6EF]/60 px-3 py-2.5">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Courier</p>
                                    <p class="mt-1 text-sm leading-relaxed text-[#1E1E1E] whitespace-pre-line break-words">{{ $courierNote }}</p>
                                </div>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-8 text-center text-[#8C8474]">No orders found.</div>
            @endforelse

            @if ($orders->hasPages())
                <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-3">{{ $orders->links() }}</div>
            @endif
        </div>
    @else
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <label class="inline-flex items-center gap-2 text-sm text-[#6B6459] cursor-pointer" title="Select all on this page">
                <input type="checkbox"
                    wire:key="select-all-{{ $isPageFullySelected ? 'on' : 'off' }}"
                    wire:click.prevent="togglePageSelection"
                    @checked($isPageFullySelected)
                    @disabled($orders->isEmpty())
                    class="rounded border-[#C9A227] text-[#C9A227] focus:ring-[#C9A227] disabled:opacity-40">
                <span>Select page</span>
            </label>
        </div>

        <div class="space-y-3" wire:loading.class="opacity-60" wire:target="switchSegment,search,nextPage,previousPage,gotoPage,refreshCourierStatuses" wire:key="staff-orders-{{ $segment }}-{{ $listRevision }}">
            @php($lastGroupKey = null)
            @php($groupByDate = in_array($segment, ['new', 'dispatched'], true))
            @forelse ($orders as $order)
                @php($isSelected = in_array($order->id, $selectedIds, true))
                @php($adminNote = filled($order->admin_note) ? \Illuminate\Support\Str::of($order->admin_note)->replace(['<br />', '<br/>', '<br>'], "\n")->stripTags()->trim() : null)
                @php($courierNote = filled($order->courier_note) ? \Illuminate\Support\Str::of($order->courier_note)->replace(['<br />', '<br/>', '<br>'], "\n")->stripTags()->trim() : null)
                @php($areaCity = collect([$order->area, $order->city])->filter()->implode(', '))
                @if ($groupByDate)
                    @php($groupDate = $segment === 'dispatched' ? $order->dispatch_date : $order->placed_at)
                    @php($groupKey = $groupDate?->timezone('Asia/Dhaka')->toDateString() ?? '_none')
                    @if ($groupKey !== $lastGroupKey)
                        <x-admin.order-date-heading
                            :date="$groupDate"
                            :kind="$segment === 'dispatched' ? 'dispatch' : 'order'"
                            :count="$dateGroupCounts[$groupKey] ?? null"
                        />
                        @php($lastGroupKey = $groupKey)
                    @endif
                @endif
                <article wire:key="order-card-{{ $order->id }}"
                    @class([
                        'rounded-xl border bg-white p-4',
                        'border-[#C9A227] bg-[#FAF6EF]/50' => $isSelected,
                        'border-[#EFE7D6]' => ! $isSelected,
                    ])>
                    <div class="flex items-start gap-3">
                        <input type="checkbox"
                            wire:key="order-select-{{ $order->id }}-{{ $isSelected ? 'on' : 'off' }}"
                            wire:click.prevent="toggleOrder({{ $order->id }})"
                            @checked($isSelected)
                            class="mt-1 rounded border-[#C9A227] text-[#C9A227] focus:ring-[#C9A227]">

                        <div class="min-w-0 flex-1 space-y-3">
                            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <a href="{{ route('admin.orders.show', $order) }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">
                                            #{{ $order->order_number }}
                                        </a>
                                        @if ($order->is_replacement)
                                            <span title="Exchange order"
                                                class="inline-flex items-center rounded border border-sky-200 bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">
                                                Exc
                                            </span>
                                        @endif
                                        <span class="rounded-full bg-[#FAF6EF] px-2 py-0.5 text-[11px] capitalize text-[#6B6459]">{{ $order->status }}</span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-[#8C8474]">{{ $order->placed_at?->format('d M Y') }}</p>
                                    <p class="mt-0.5 text-xs text-[#8C8474]">Created by {{ $order->createdByLabel() }}</p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-[11px] uppercase tracking-wide text-[#8C8474]">COD</p>
                                    <p class="text-sm font-semibold tabular-nums text-[#1E1E1E]">&#2547; {{ number_format($order->total, 0) }}</p>
                                    @php($netRevenue = $order->netRevenue())
                                    <p class="mt-1 text-[11px] text-[#8C8474]">Net
                                        <span @class(['tabular-nums font-medium', 'text-rose-600' => $netRevenue < 0, 'text-[#6B6459]' => $netRevenue >= 0])>&#2547;{{ number_format($netRevenue, 0) }}</span>
                                    </p>
                                    @if ((float) $order->due_amount > 0 && (float) $order->paid_amount > 0)
                                        <p class="text-[11px] text-[#8C8474]">Due &#2547;{{ number_format($order->due_amount, 0) }}</p>
                                    @endif
                                    @if ((float) $order->delivery_charge > 0 || (float) $order->courier_charge > 0)
                                        <p class="hidden sm:block mt-1 text-[10px] leading-snug text-[#8C8474] tabular-nums">
                                            Del &#2547;{{ number_format($order->delivery_charge, 0) }}
                                            &middot; Cour &#2547;{{ number_format($order->courier_charge, 0) }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <div class="min-w-0">
                                @if ($order->user_id)
                                    <a href="{{ route('admin.customers.show', $order->user_id) }}" wire:navigate
                                        class="font-medium text-[#C9A227] hover:underline">
                                        {{ $order->name }}
                                    </a>
                                @else
                                    <div class="font-medium text-[#1E1E1E]">{{ $order->name }}</div>
                                @endif
                                <div class="mt-0.5 cursor-pointer"
                                    role="button"
                                    tabindex="0"
                                    title="Click to copy phone"
                                    data-phone="{{ $order->phone }}"
                                    onclick="window.sunCopyText(this.dataset.phone, this.querySelector('[data-copy-feedback]'))"
                                    onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.click(); }">
                                    <div class="flex flex-wrap items-center gap-1.5 text-sm text-[#8C8474]">
                                        <span>{{ $order->phone }}</span>
                                        <span data-copy-feedback class="hidden text-[10px] font-semibold uppercase text-emerald-600">Copied</span>
                                    </div>
                                </div>
                                @if ($areaCity !== '' || filled($order->address))
                                    <div x-data="{ open: false }" class="mt-0.5">
                                        <button type="button"
                                            @click="open = ! open"
                                            class="text-left text-xs leading-snug text-[#8C8474] hover:text-[#C9A227]">
                                            {{ $areaCity !== '' ? $areaCity : 'Show address' }}
                                        </button>
                                        @if (filled($order->address))
                                            <p x-show="open" x-cloak class="mt-1 text-xs leading-snug text-[#6B6459] break-words">{{ $order->address }}</p>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            <div>
                                @if ($order->items->isEmpty())
                                    <span class="text-sm text-[#8C8474]">—</span>
                                @else
                                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                                        @foreach ($order->items as $item)
                                            <x-order-product-thumb
                                                :item="$item"
                                                size="md"
                                                show-quantity
                                                :show-return="$segment === 'return-pending'"
                                            />
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            @if ($adminNote || $courierNote)
                                <div class="space-y-2 border-t border-[#EFE7D6] pt-3">
                                    @if ($adminNote)
                                        <div class="rounded-lg border-2 border-rose-500 bg-rose-50 px-3 py-2.5">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-rose-700">Admin</p>
                                            <p class="mt-1 text-sm font-medium leading-relaxed text-rose-800 whitespace-pre-line break-words">{{ $adminNote }}</p>
                                        </div>
                                    @endif
                                    @if ($courierNote)
                                        <div class="rounded-lg border border-[#E7DFCF] bg-[#FAF6EF]/60 px-3 py-2.5">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Courier</p>
                                            <p class="mt-1 text-sm leading-relaxed text-[#1E1E1E] whitespace-pre-line break-words">{{ $courierNote }}</p>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center gap-2 border-t border-[#EFE7D6] pt-3">
                                @if ($segment === 'new')
                                    <button type="button"
                                        wire:click="quickDispatch({{ $order->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="quickDispatch({{ $order->id }})"
                                        title="Dispatch via default courier"
                                        class="inline-flex h-8 items-center justify-center rounded-lg border border-[#E0D6C2] bg-white px-2.5 text-xs font-semibold text-[#1E1E1E] hover:border-[#C9A227] hover:text-[#C9A227] disabled:opacity-60">
                                        <span wire:loading.remove wire:target="quickDispatch({{ $order->id }})">Dispatch</span>
                                        <span wire:loading wire:target="quickDispatch({{ $order->id }})">…</span>
                                    </button>
                                @endif
                                @if ($segment === 'dispatched')
                                    <button type="button"
                                        wire:click="markDelivered({{ $order->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="markDelivered({{ $order->id }})"
                                        class="inline-flex h-8 items-center justify-center rounded-lg border border-emerald-200 bg-white px-2.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-60">
                                        Delivered
                                    </button>
                                    <button type="button"
                                        wire:click="openPartialReturn({{ $order->id }})"
                                        class="inline-flex h-8 items-center justify-center rounded-lg border border-[#E0D6C2] bg-white px-2.5 text-xs font-semibold text-[#6B6459] hover:bg-[#FAF6EF]">
                                        Partial
                                    </button>
                                    <button type="button"
                                        wire:click="cancelAndReturn({{ $order->id }})"
                                        wire:confirm="Cancel &amp; return order #{{ $order->order_number }} with no delivery charge?"
                                        class="inline-flex h-8 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                        Cancel/Return
                                    </button>
                                @endif
                                @if ($segment === 'return-pending')
                                    @php($hasPendingReturn = $order->items->contains(fn ($item) => (int) $item->returned_quantity > 0 && ! $item->return_received))
                                    @php($hasReceivedReturn = $order->items->contains(fn ($item) => (bool) $item->return_received))
                                    <button type="button"
                                        wire:click="markReturnReceived({{ $order->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="markReturnReceived({{ $order->id }})"
                                        @disabled(! $hasPendingReturn)
                                        class="inline-flex h-8 items-center justify-center rounded-lg border border-emerald-200 bg-white px-2.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-40">
                                        Received
                                    </button>
                                    <button type="button"
                                        wire:click="undoReturnReceived({{ $order->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="undoReturnReceived({{ $order->id }})"
                                        @disabled(! $hasReceivedReturn)
                                        class="inline-flex h-8 items-center justify-center rounded-lg border border-amber-200 bg-white px-2.5 text-xs font-semibold text-amber-700 hover:bg-amber-50 disabled:opacity-40">
                                        Undo
                                    </button>
                                    <button type="button"
                                        wire:click="toggleHasReturn({{ $order->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="toggleHasReturn({{ $order->id }})"
                                        @class([
                                            'inline-flex h-8 items-center justify-center rounded-lg border px-2.5 text-xs font-semibold disabled:opacity-60',
                                            'border-[#C9A227] bg-[#C9A227] text-white' => $order->has_return,
                                            'border-[#E0D6C2] bg-white text-[#6B6459] hover:bg-[#FAF6EF]' => ! $order->has_return,
                                        ])>
                                        H/R
                                    </button>
                                @endif
                                <a href="{{ route('admin.orders.print', $order) }}" target="_blank"
                                    title="Print label"
                                    class="inline-flex h-8 items-center justify-center rounded-lg border border-[#E0D6C2] bg-white px-2.5 text-xs font-semibold text-[#6B6459] hover:bg-[#FAF6EF]">
                                    Print
                                </a>
                                <a href="{{ route('admin.orders.create', ['repeat' => $order->id]) }}"
                                    title="Repeat order"
                                    class="inline-flex h-8 items-center justify-center rounded-lg border border-[#E0D6C2] bg-white px-2.5 text-xs font-semibold text-[#6B6459] hover:bg-[#FAF6EF]">
                                    Repeat
                                </a>
                                <button type="button"
                                    wire:click="deleteOrder({{ $order->id }})"
                                    wire:confirm="Delete order #{{ $order->order_number }} and restore product stock?"
                                    title="Delete order"
                                    class="inline-flex h-8 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                    Delete
                                </button>
                            </div>

                            @if ($segment === 'dispatched')
                                @php($events = $trackingByOrder[$order->id]['events'] ?? [])
                                @php($courierStatus = $trackingByOrder[$order->id]['status'] ?? null)
                                <div wire:key="order-tracking-{{ $order->id }}" x-data="{ open: true }" class="overflow-hidden rounded-lg border border-[#EFE7D6]">
                                    <button
                                        type="button"
                                        class="flex w-full items-center justify-between gap-3 bg-[#FAF6EF] px-3 py-2.5 text-left hover:bg-[#F5EFE3]"
                                        @click="open = ! open"
                                        :aria-expanded="open.toString()"
                                    >
                                        <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-[#1E1E1E]">
                                            <span class="font-semibold uppercase tracking-wide">{{ $order->courier?->name ?? '—' }}</span>
                                            <span class="text-[#C9B99A]">|</span>
                                            <span class="break-all text-[#6B6459]">{{ $order->courier_tracker ?: '—' }}</span>
                                            <span class="text-[#C9B99A]">|</span>
                                            <span class="font-semibold uppercase tracking-wide">
                                                <span wire:loading.delay wire:target="refreshCourierStatuses">Loading…</span>
                                                <span wire:loading.remove wire:target="refreshCourierStatuses">
                                                    {{ $courierStatus ? strtoupper($courierStatus) : '—' }}
                                                </span>
                                            </span>
                                        </span>
                                        <svg class="h-4 w-4 shrink-0 text-[#8C8474] transition-transform" :class="open ? 'rotate-180' : ''" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                        </svg>
                                    </button>
                                    <div class="border-t border-[#E7DFCF] bg-white px-3 py-2.5" x-show="open" x-cloak>
                                        @if (count($events) === 0)
                                            <p class="text-xs text-[#8C8474]">No tracking updates yet.</p>
                                        @else
                                            <ul class="m-0 list-none space-y-1.5 p-0">
                                                @foreach ($events as $event)
                                                    <li class="flex gap-3 text-xs leading-snug text-[#1E1E1E]">
                                                        <span class="w-[5.5rem] shrink-0 tabular-nums text-[#5B8DEF]">{{ $event['at'] }}</span>
                                                        <span class="min-w-0 break-words">{{ $event['message'] }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-8 text-center text-[#8C8474]">No orders found.</div>
            @endforelse

            @if ($orders->hasPages())
                <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-3">{{ $orders->links() }}</div>
            @endif
        </div>
    @endif

    @teleport('body')
        <div wire:key="admin-orders-overlays">
            @unless ($readOnly)
            <div wire:key="admin-order-selection-bar"
                @class([
                    'admin-order-selection-bar fixed bottom-0 left-0 right-0 z-50',
                    'hidden' => $selectedCount === 0,
                ])
                role="status"
                aria-live="polite">
                <div class="mx-auto flex max-w-[1600px] flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-6">
                    <div class="flex items-center justify-between gap-3 sm:justify-start sm:gap-4">
                        <button type="button" wire:click="clearSelection"
                            class="admin-order-selection-bar__clear shrink-0 text-xs transition">
                            Clear
                        </button>
                        <p class="min-w-0 text-right text-sm font-medium tabular-nums tracking-wide sm:text-left">
                            <span class="whitespace-nowrap">{{ number_format($selectedCount) }} selected</span>
                            <span class="text-white/50"> · </span>
                            <span class="whitespace-nowrap">&#2547; {{ number_format($selectedTotal, 0) }} Tk</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($segment === 'new')
                            <button type="button" wire:click="openSendTo"
                                class="rounded-full bg-[#C9A227] px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-[#b8931f]">
                                Send to
                            </button>
                            <button type="button" wire:click="openDispatch"
                                class="rounded-full border border-white/40 bg-white/10 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-white/20">
                                Dispatch
                            </button>
                        @endif
                        @if ($segment === 'dispatched')
                            <button type="button" wire:click="openSendTo"
                                class="rounded-full bg-[#C9A227] px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-[#b8931f]">
                                Send to
                            </button>
                            <button type="button"
                                wire:click="markSelectedDelivered"
                                wire:confirm="Mark {{ $selectedCount }} selected order(s) as delivered?"
                                class="rounded-full bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                                Delivered
                            </button>
                        @endif
                        <button type="button"
                            wire:click="listSelectedProducts"
                            wire:loading.attr="disabled"
                            wire:target="listSelectedProducts"
                            class="rounded-full border border-white/40 bg-white/10 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-white/20 disabled:opacity-60">
                            List products
                        </button>
                        <button type="button"
                            wire:click="printSelected"
                            wire:loading.attr="disabled"
                            wire:target="printSelected"
                            class="rounded-full border border-white/40 bg-white/10 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-white/20 disabled:opacity-60">
                            Print
                        </button>
                    </div>
                </div>
            </div>

            <div>
                @if ($showSendToModal)
                    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" wire:click.self="closeSendModals">
                        <div class="w-full max-w-md rounded-xl border border-[#EFE7D6] bg-white p-6 shadow-xl space-y-4" wire:click.stop>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h2 class="font-semibold text-lg">Send to courier</h2>
                                    <p class="text-xs text-[#8C8474] mt-1">
                                        @if ($segment === 'dispatched')
                                            Re-send {{ number_format($selectedCount) }} selected order(s) to the courier API.
                                            Tracking codes are replaced; status stays dispatched.
                                        @else
                                            Send {{ number_format($selectedCount) }} selected order(s) to the courier API.
                                            Status stays unchanged until you mark them dispatched.
                                        @endif
                                    </p>
                                </div>
                                <button type="button" wire:click="closeSendModals" class="text-sm text-[#8C8474] hover:text-[#1E1E1E]">Close</button>
                            </div>

                            @if ($apiCouriers->isEmpty())
                                <p class="text-sm text-rose-600">No courier APIs are configured. Add credentials in <code class="text-xs">.env</code>.</p>
                            @else
                                <div>
                                    <label class="block text-sm font-medium mb-1">Courier</label>
                                    <select wire:model="sendToCourierSlug"
                                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                                        @foreach ($apiCouriers as $apiCourier)
                                            <option value="{{ $apiCourier->slug }}">{{ $apiCourier->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('sendToCourierSlug') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </div>

                                <div class="flex flex-wrap gap-3 pt-1">
                                    <button type="button" wire:click="startBulkSend"
                                        class="rounded-full bg-[#C9A227] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                                        Start sending
                                    </button>
                                    <button type="button" wire:click="closeSendModals"
                                        class="rounded-full border border-[#E0D6C2] px-6 py-2.5 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                                        Cancel
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            <div>
                @if ($showDispatchModal)
                    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" wire:click.self="closeSendModals">
                        <div class="w-full max-w-md rounded-xl border border-[#EFE7D6] bg-white p-6 shadow-xl space-y-4" wire:click.stop>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h2 class="font-semibold text-lg">Dispatch orders</h2>
                                    <p class="text-xs text-[#8C8474] mt-1">
                                        Mark {{ number_format($selectedCount) }} selected order(s) as dispatched and update courier book balance.
                                    </p>
                                </div>
                                <button type="button" wire:click="closeSendModals" class="text-sm text-[#8C8474] hover:text-[#1E1E1E]">Close</button>
                            </div>

                            @if ($dispatchCouriers->isEmpty())
                                <p class="text-sm text-rose-600">No active couriers found. Add a courier first.</p>
                            @else
                                <div>
                                    <label class="block text-sm font-medium mb-1">Courier</label>
                                    <select wire:model="dispatchCourierId"
                                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                                        @foreach ($dispatchCouriers as $courier)
                                            <option value="{{ $courier->id }}">{{ $courier->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('dispatchCourierId') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                </div>

                                <div class="flex flex-wrap gap-3 pt-1">
                                    <button type="button" wire:click="submitBulkDispatch"
                                        class="rounded-full bg-[#1E1E1E] px-6 py-2.5 text-sm font-semibold text-white hover:bg-black">
                                        Mark dispatched
                                    </button>
                                    <button type="button" wire:click="closeSendModals"
                                        class="rounded-full border border-[#E0D6C2] px-6 py-2.5 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                                        Cancel
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            <div>
                @if ($showPartialModal)
                    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" wire:click.self="closePartialModal">
                        <div class="w-full max-w-lg rounded-xl border border-[#EFE7D6] bg-white p-6 shadow-xl space-y-4 max-h-[85vh] overflow-y-auto" wire:click.stop>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h2 class="font-semibold text-lg">Partial return</h2>
                                    <p class="text-xs text-[#8C8474] mt-1">Order #{{ $partialOrderNumber }}</p>
                                </div>
                                <button type="button" wire:click="closePartialModal" class="text-sm text-[#8C8474] hover:text-[#1E1E1E]">Close</button>
                            </div>

                            <div class="space-y-3">
                                @forelse ($partialItems as $item)
                                    <div wire:key="partial-item-{{ $item['id'] }}" class="flex items-center gap-3 rounded-lg border border-[#EFE7D6] px-3 py-2">
                                        @if ($item['image'])
                                            <img src="{{ $item['image'] }}" alt="" class="h-10 w-10 rounded object-cover">
                                        @else
                                            <div class="h-10 w-10 rounded bg-[#FAF6EF]"></div>
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium">{{ $item['name'] }}</p>
                                            <p class="text-xs text-[#8C8474]">Ordered: {{ $item['quantity'] }}</p>
                                        </div>
                                        <div class="w-24">
                                            <label class="mb-0.5 block text-[10px] uppercase tracking-wide text-[#8C8474]">Returned</label>
                                            <input type="number" min="0" max="{{ $item['quantity'] }}"
                                                wire:model="partialReturns.{{ $item['id'] }}"
                                                class="w-full rounded-lg border border-[#E0D6C2] px-2 py-1.5 text-sm">
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-[#8C8474]">No products on this order.</p>
                                @endforelse
                                @error('partialReturns') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                                @error('partialReturns.*') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium">Collected Tk</label>
                                <input type="number" min="0" step="1" wire:model="partialCollectedTk"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                                @error('partialCollectedTk') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                                <p class="mt-1 text-xs text-[#8C8474]">
                                    All products returned → Cancelled. Some kept → Delivered. Both set has_return.
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-3 pt-1">
                                <button type="button" wire:click="submitPartialReturn"
                                    class="rounded-full bg-[#1E1E1E] px-6 py-2.5 text-sm font-semibold text-white hover:bg-black">
                                    Submit partial
                                </button>
                                <button type="button" wire:click="closePartialModal"
                                    class="rounded-full border border-[#E0D6C2] px-6 py-2.5 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div>
                @if ($showBulkSendProgress)
                    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" @if (! $bulkSending) wire:click.self="closeSendModals" @endif>
                        <div class="w-full max-w-lg rounded-xl border border-[#EFE7D6] bg-white p-6 shadow-xl space-y-4 max-h-[85vh] flex flex-col" wire:click.stop>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h2 class="font-semibold text-lg">Sending to {{ strtoupper($sendToCourierSlug) }}</h2>
                                    <p class="text-xs text-[#8C8474] mt-1">
                                        @if ($bulkSending)
                                            Sending selected orders to the courier API…
                                        @else
                                            Finished.
                                        @endif
                                    </p>
                                </div>
                                @unless ($bulkSending)
                                    <button type="button" wire:click="closeSendModals" class="text-sm text-[#8C8474] hover:text-[#1E1E1E]">Close</button>
                                @endunless
                            </div>

                            <ul class="space-y-2 overflow-y-auto flex-1 pr-1">
                                @foreach ($bulkSendRows as $row)
                                    <li wire:key="bulk-send-{{ $row['order_id'] }}"
                                        class="rounded-lg border border-[#EFE7D6] px-3 py-2 text-sm">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p class="font-medium">#{{ $row['order_number'] }} · {{ $row['customer'] }}</p>
                                                @if ($row['message'])
                                                    <p class="text-xs mt-0.5 {{ $row['status'] === 'failed' ? 'text-rose-600' : 'text-[#6B6459]' }}">
                                                        {{ $row['message'] }}
                                                    </p>
                                                @endif
                                            </div>
                                            <span @class([
                                                'shrink-0 text-xs font-semibold uppercase tracking-wide',
                                                'text-[#8C8474]' => $row['status'] === 'pending',
                                                'text-amber-700' => $row['status'] === 'sending',
                                                'text-emerald-700' => $row['status'] === 'success',
                                                'text-rose-600' => $row['status'] === 'failed',
                                            ])>
                                                @switch($row['status'])
                                                    @case('pending') Waiting @break
                                                    @case('sending') Sending @break
                                                    @case('success') Success @break
                                                    @case('failed') Failed @break
                                                    @default {{ $row['status'] }}
                                                @endswitch
                                            </span>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>

                            @unless ($bulkSending)
                                <button type="button" wire:click="closeSendModals"
                                    class="rounded-full bg-[#1E1E1E] px-6 py-2.5 text-sm font-semibold text-white hover:bg-black self-start">
                                    Done
                                </button>
                            @endunless
                        </div>
                    </div>
                @endif
            </div>
            @endunless
        </div>
    @endteleport
</div>
