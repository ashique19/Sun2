@props([
    'variant' => 'header',
])

@php
    $classes = match ($variant) {
        'thread-icon' => 'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227] disabled:opacity-60 xl:hidden',
        'thread-label' => 'hidden h-10 shrink-0 items-center justify-center rounded-full border border-[#E0D6C2] bg-white px-3 text-xs font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227] disabled:opacity-60 xl:inline-flex',
        default => 'inline-flex h-10 w-10 items-center justify-center rounded-full border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227] disabled:opacity-60 xl:h-auto xl:w-auto xl:gap-2 xl:px-4 xl:py-2 xl:text-sm xl:font-semibold',
    };
    $label = $variant === 'header' ? 'Sync' : 'Sync from Facebook';
@endphp

<button type="button"
    wire:click="syncFromFacebook"
    wire:loading.attr="disabled"
    wire:target="syncFromFacebook"
    title="{{ $label }}"
    aria-label="{{ $label }}"
    {{ $attributes->class($classes) }}>
    @if ($variant === 'header')
        <svg wire:loading.remove wire:target="syncFromFacebook" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 xl:hidden" aria-hidden="true">
            <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H4.39a.75.75 0 0 0-.75.75v3.842a.75.75 0 0 0 1.5 0v-2.14l.311.311a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm-10.623-2.85a5.5 5.5 0 0 1 9.201-2.466l.312.311H11.77a.75.75 0 0 0 0 1.5h3.843a.75.75 0 0 0 .75-.75V3.328a.75.75 0 1 0-1.5 0V5.47l-.311-.311A7 7 0 0 0 3.04 8.295a.75.75 0 1 0 1.45.39Z" clip-rule="evenodd" />
        </svg>
        <svg wire:loading wire:target="syncFromFacebook" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5 animate-spin xl:hidden" aria-hidden="true">
            <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H4.39a.75.75 0 0 0-.75.75v3.842a.75.75 0 0 0 1.5 0v-2.14l.311.311a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm-10.623-2.85a5.5 5.5 0 0 1 9.201-2.466l.312.311H11.77a.75.75 0 0 0 0 1.5h3.843a.75.75 0 0 0 .75-.75V3.328a.75.75 0 1 0-1.5 0V5.47l-.311-.311A7 7 0 0 0 3.04 8.295a.75.75 0 1 0 1.45.39Z" clip-rule="evenodd" />
        </svg>
        <span class="hidden xl:inline" wire:loading.remove wire:target="syncFromFacebook">Sync</span>
        <span class="hidden xl:inline" wire:loading wire:target="syncFromFacebook">Syncing…</span>
    @elseif ($variant === 'thread-icon')
        <span wire:loading.remove wire:target="syncFromFacebook">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H4.39a.75.75 0 0 0-.75.75v3.842a.75.75 0 0 0 1.5 0v-2.14l.311.311a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm-10.623-2.85a5.5 5.5 0 0 1 9.201-2.466l.312.311H11.77a.75.75 0 0 0 0 1.5h3.843a.75.75 0 0 0 .75-.75V3.328a.75.75 0 1 0-1.5 0V5.47l-.311-.311A7 7 0 0 0 3.04 8.295a.75.75 0 1 0 1.45.39Z" clip-rule="evenodd" />
            </svg>
        </span>
        <span wire:loading wire:target="syncFromFacebook">…</span>
    @else
        <span wire:loading.remove wire:target="syncFromFacebook">Sync</span>
        <span wire:loading wire:target="syncFromFacebook">…</span>
    @endif
</button>
