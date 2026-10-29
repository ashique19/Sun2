@props([
    'order',
    'events' => [],
    'courierStatus' => null,
    'pending' => false,
])

<div {{ $attributes->class('overflow-hidden rounded-lg border border-[#EFE7D6]') }} x-data="{ open: true }">
    <button
        type="button"
        class="flex w-full items-center justify-between gap-3 bg-[#FAF6EF] px-3 py-2.5 text-left hover:bg-[#F5EFE3]"
        @click="open = ! open"
        :aria-expanded="open.toString()"
    >
        <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-[#1E1E1E]">
            @if ($steadfastUrl = $order->steadfastConsignmentUrl())
                <a href="{{ $steadfastUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="font-semibold uppercase tracking-wide text-[#C9A227] hover:underline"
                    title="Open Steadfast consignment"
                    @click.stop>
                    {{ $order->courier?->name ?? 'Steadfast' }} ↗
                </a>
            @else
                <span class="font-semibold uppercase tracking-wide">{{ $order->courier?->name ?? '—' }}</span>
            @endif
            <span class="text-[#C9B99A]">|</span>
            <span class="break-all text-[#6B6459]">{{ $order->courier_tracker ?: '—' }}</span>
            <span class="text-[#C9B99A]">|</span>
            <span class="font-semibold uppercase tracking-wide" @if ($pending) aria-busy="true" @endif>
                @if ($pending)
                    <span class="inline-flex items-center gap-1 text-[#C9A227]" data-admin-courier-live-spinner>
                        <svg class="h-3 w-3 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span>Loading…</span>
                    </span>
                @else
                    {{ $courierStatus ? strtoupper($courierStatus) : '—' }}
                @endif
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
