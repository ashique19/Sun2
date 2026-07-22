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
    <img
        src="{{ $src }}"
        @if ($srcset) srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
        alt="{{ $alt }}"
        @if ($priority) fetchpriority="high" @else loading="lazy" @endif
        decoding="async"
        width="400"
        height="400"
        {{ $attributes->class('bg-[#F1EADB]') }}
    >
@endif
