@props([
    'product',
    'routeName',
])

@php
    $previous = \App\Support\AdminProductNavigator::previous($product);
    $next = \App\Support\AdminProductNavigator::next($product);
    $previousUrl = $previous ? route($routeName, $previous) : null;
    $nextUrl = $next ? route($routeName, $next) : null;
    $buttonClass = 'inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[#E0D6C2] bg-white text-[#6B6459] opacity-80 hover:bg-[#FAF6EF] hover:opacity-100';
    $disabledClass = 'inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[#E0D6C2] bg-white text-[#6B6459] opacity-35 cursor-not-allowed';
@endphp

<div
    {{ $attributes->class('flex items-center gap-1.5') }}
    role="group"
    aria-label="Product navigation"
    data-product-swipe-nav
    data-previous-url="{{ $previousUrl ?? '' }}"
    data-next-url="{{ $nextUrl ?? '' }}"
    x-data="{
        previousUrl: @js($previousUrl),
        nextUrl: @js($nextUrl),
        startX: null,
        startY: null,
        onStart(event) {
            if (! window.matchMedia('(max-width: 767px)').matches) {
                return;
            }

            const touch = event.changedTouches?.[0];
            if (! touch) {
                return;
            }

            this.startX = touch.clientX;
            this.startY = touch.clientY;
        },
        onEnd(event) {
            if (this.startX === null || this.startY === null) {
                return;
            }

            if (! window.matchMedia('(max-width: 767px)').matches) {
                this.startX = null;
                this.startY = null;

                return;
            }

            const touch = event.changedTouches?.[0];
            if (! touch) {
                this.startX = null;
                this.startY = null;

                return;
            }

            const dx = touch.clientX - this.startX;
            const dy = touch.clientY - this.startY;
            this.startX = null;
            this.startY = null;

            if (Math.abs(dx) < 70 || Math.abs(dx) <= Math.abs(dy)) {
                return;
            }

            const target = event.target;
            if (target instanceof Element && target.closest('input, textarea, select, [contenteditable=\"true\"], .no-product-swipe-nav')) {
                return;
            }

            const url = dx > 0 ? this.previousUrl : this.nextUrl;
            if (! url) {
                return;
            }

            if (window.Livewire && typeof window.Livewire.navigate === 'function') {
                window.Livewire.navigate(url);
            } else {
                window.location.assign(url);
            }
        },
    }"
    @touchstart.window.passive="onStart($event)"
    @touchend.window.passive="onEnd($event)"
>
    @if ($previous)
        <a href="{{ $previousUrl }}"
            wire:navigate
            title="Previous product"
            aria-label="Previous product"
            class="{{ $buttonClass }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/>
            </svg>
        </a>
    @else
        <span title="No previous product" aria-label="No previous product" aria-disabled="true" class="{{ $disabledClass }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                <path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/>
            </svg>
        </span>
    @endif

    @if ($next)
        <a href="{{ $nextUrl }}"
            wire:navigate
            title="Next product"
            aria-label="Next product"
            class="{{ $buttonClass }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
            </svg>
        </a>
    @else
        <span title="No next product" aria-label="No next product" aria-disabled="true" class="{{ $disabledClass }}">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
            </svg>
        </span>
    @endif
</div>
