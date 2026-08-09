@props([
    'categories',
    'collapsible' => false,
    'filters' => null,
])

@php
    $filters = $filters ?? \App\Support\AdminProductListFilters::fromArray([]);
    $activeCount = $filters->activeCount();
@endphp

@if ($collapsible)
    <div
        class="mb-6 rounded-xl border border-[#EFE7D6] bg-white overflow-hidden"
        x-data="{ open: false }"
        data-product-list-filters
    >
        <button
            type="button"
            class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-[#FAF6EF]/60"
            @click="open = ! open"
            :aria-expanded="open.toString()"
            aria-controls="product-list-filters-panel"
        >
            <span class="min-w-0">
                <span class="block text-sm font-medium text-[#1E1E1E]">Search &amp; filters</span>
                <span class="mt-0.5 block truncate text-xs text-[#8C8474]">
                    @if ($activeCount > 0)
                        {{ $activeCount }} active · scopes next/previous
                    @else
                        Collapsed · next/previous use all products
                    @endif
                </span>
            </span>
            <span class="flex shrink-0 items-center gap-2">
                @if ($activeCount > 0)
                    <span class="rounded-md bg-[#FAF6EF] px-2 py-0.5 text-[11px] font-medium tabular-nums text-[#6B6459]">
                        {{ $activeCount }}
                    </span>
                @endif
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                    class="h-4 w-4 text-[#8C8474] transition-transform"
                    :class="open && 'rotate-180'"
                    aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                </svg>
            </span>
        </button>

        <div
            id="product-list-filters-panel"
            x-show="open"
            x-cloak
            class="border-t border-[#EFE7D6] px-4 py-3"
        >
            <div class="flex flex-wrap gap-3">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, SKU…"
                    class="min-w-[12rem] flex-1 rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                <input type="number" min="0" step="1" inputmode="numeric"
                    wire:model.live.debounce.300ms="priceMin"
                    placeholder="Min price"
                    class="w-28 rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm tabular-nums">
                <input type="number" min="0" step="1" inputmode="numeric"
                    wire:model.live.debounce.300ms="priceMax"
                    placeholder="Max price"
                    class="w-28 rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm tabular-nums">
                <select wire:model.live="category" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    <option value="">All categories</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="published" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    <option value="">All</option>
                    <option value="1">Published</option>
                    <option value="0">Draft</option>
                </select>
                @if ($activeCount > 0)
                    <button type="button"
                        wire:click="clearAdminProductListFilters"
                        class="rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm text-[#6B6459] hover:border-[#C9A227] hover:text-[#1E1E1E]">
                        Clear
                    </button>
                @endif
            </div>
        </div>
    </div>
@else
    <div {{ $attributes->class('rounded-xl border border-[#EFE7D6] bg-white p-4 mb-6 flex flex-wrap gap-3') }} data-product-list-filters>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, SKU…"
            class="flex-1 min-w-[12rem] rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
        <input type="number" min="0" step="1" inputmode="numeric"
            wire:model.live.debounce.300ms="priceMin"
            placeholder="Min price"
            class="w-28 rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm tabular-nums">
        <input type="number" min="0" step="1" inputmode="numeric"
            wire:model.live.debounce.300ms="priceMax"
            placeholder="Max price"
            class="w-28 rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm tabular-nums">
        <select wire:model.live="category" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            <option value="">All categories</option>
            @foreach ($categories as $cat)
                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="published" class="rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
            <option value="">All</option>
            <option value="1">Published</option>
            <option value="0">Draft</option>
        </select>
    </div>
@endif
