@props([
    'path',
    'alt' => '',
    'sizes' => '(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 25vw',
    'priority' => false,
])

@php
    // Default src is the small variant so slow mobile networks do not download md/lg first.
    $src = \App\Support\StorefrontAssets::smallUrl($path)
        ?? \App\Support\StorefrontAssets::variantUrl($path, 'xs')
        ?? \App\Support\StorefrontAssets::url($path);
    $srcset = \App\Support\StorefrontAssets::srcset($path, [
        'xs' => 200,
        'sm' => 400,
        'md' => 800,
    ]);
@endphp

@if ($src)
    <div class="relative" data-image-retry-wrap data-image-loading>
        <img
            src="{{ $src }}"
            data-image-retry
            data-image-src="{{ $src }}"
            @if ($srcset) srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
            alt="{{ $alt }}"
            @if ($priority) fetchpriority="high" @else loading="lazy" @endif
            decoding="async"
            width="400"
            height="400"
            {{ $attributes->class('bg-[#F1EADB]') }}
        >
        <div data-image-loading-spinner
            class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center bg-[#F1EADB]"
            aria-hidden="true">
            <span class="size-6 animate-spin rounded-full border-2 border-[#D8CDB6] border-t-[#C9A227]"></span>
        </div>
        <button type="button"
            hidden
            data-image-retry-btn
            class="absolute inset-0 z-20 flex items-center justify-center bg-[#F1EADB]/90 px-2 text-center text-xs font-medium text-[#7A6114]">
            {{ __('storefront.image_retry') }}
        </button>
    </div>
@endif
