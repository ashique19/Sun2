@props([
    'order',
    'compact' => false,
])

@php($variants = \App\Support\OrderPrintVariant::cases())

<div
    {{ $attributes->class(['relative inline-flex']) }}
    x-data="{ open: false }"
    @click.outside="open = false"
    data-order-print-menu
>
    @if ($compact)
        <button type="button"
            @click.stop="open = ! open"
            title="Print"
            aria-label="Print"
            :aria-expanded="open.toString()"
            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[#E0D6C2] bg-white opacity-80 hover:bg-[#FAF6EF] hover:opacity-100">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                <path fill="#6B6459" d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/>
            </svg>
        </button>
    @else
        <button type="button"
            @click.stop="open = ! open"
            title="Print"
            :aria-expanded="open.toString()"
            class="inline-flex h-8 items-center justify-center gap-1 rounded-lg border border-[#E0D6C2] bg-white px-2.5 text-xs font-semibold text-[#6B6459] hover:bg-[#FAF6EF]">
            Print
            <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </button>
    @endif

    <div
        x-show="open"
        x-cloak
        x-transition
        class="absolute right-0 z-30 mt-1 w-72 origin-top-right rounded-lg border border-[#EFE7D6] bg-white py-1 shadow-lg"
        role="menu"
    >
        @foreach ($variants as $printVariant)
            <a href="{{ route('admin.orders.print', ['order' => $order, 'variant' => $printVariant->value]) }}"
                target="_blank"
                rel="noopener noreferrer"
                @click="open = false"
                role="menuitem"
                data-order-print-variant="{{ $printVariant->value }}"
                class="block px-3 py-2 text-left text-xs font-medium leading-snug text-[#1E1E1E] hover:bg-[#FAF6EF]">
                {{ $printVariant->label() }}
            </a>
        @endforeach
    </div>
</div>
