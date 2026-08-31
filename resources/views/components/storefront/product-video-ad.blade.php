@props([
    'src',
])

@php
    $raw = trim((string) $src);
    $scriptSrc = str_starts_with($raw, '//')
        ? 'https:'.$raw
        : $raw;
@endphp

{{-- HilltopAds-style video loader; reinject on Livewire navigate remounts. --}}
<div
    {{ $attributes->merge(['class' => 'storefront-product-video-ad']) }}
    data-product-video-ad
    wire:ignore
    x-data
    x-init="
        const src = @js($scriptSrc);
        if (! src) {
            return;
        }
        const existing = $el.querySelector('script[data-product-video-invoke]');
        if (existing) {
            existing.remove();
        }
        const s = document.createElement('script');
        s.settings = {};
        s.src = src;
        s.async = true;
        s.referrerPolicy = 'no-referrer-when-downgrade';
        s.dataset.productVideoInvoke = '1';
        $el.appendChild(s);
    "
    aria-hidden="true"
></div>
