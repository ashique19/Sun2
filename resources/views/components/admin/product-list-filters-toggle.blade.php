@props([
    'filters' => null,
])

@php
    $filters = $filters ?? \App\Support\AdminProductListFilters::fromArray([]);
    $activeCount = $filters->activeCount();
@endphp

<button
    type="button"
    {{ $attributes->class([
        'relative inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border bg-white transition',
        'border-[#C9A227] text-[#C9A227]' => $activeCount > 0,
        'border-[#E0D6C2] text-[#6B6459] hover:border-[#C9A227] hover:bg-[#FAF6EF] hover:text-[#C9A227]' => $activeCount === 0,
    ]) }}
    @click="filtersOpen = ! filtersOpen"
    :aria-expanded="filtersOpen.toString()"
    aria-controls="product-list-filters-panel"
    aria-label="Search and filters"
    title="Search and filters"
    data-product-list-filters-toggle
>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
        <path fill-rule="evenodd" d="M2.628 1.601C5.028 1.206 7.49 1 10 1s4.973.206 7.372.601a.75.75 0 0 1 .628.74v2.288a2.25 2.25 0 0 1-.659 1.59l-4.682 4.683a2.25 2.25 0 0 0-.659 1.591v3.037c0 .818-.46 1.56-1.18 1.919l-2.12 1.06A.75.75 0 0 1 8 18.25v-5.757a2.25 2.25 0 0 0-.659-1.591L2.659 6.22A2.25 2.25 0 0 1 2 4.629V2.34a.75.75 0 0 1 .628-.74Z" clip-rule="evenodd"/>
    </svg>
    @if ($activeCount > 0)
        <span class="absolute -right-1 -top-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-[#C9A227] px-1 text-[10px] font-semibold leading-none text-white tabular-nums">
            {{ $activeCount }}
        </span>
    @endif
</button>
