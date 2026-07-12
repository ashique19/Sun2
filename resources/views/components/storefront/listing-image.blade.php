@props([
    'path',
    'alt' => '',
    'sizes' => '(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 25vw',
])

@php
    $src = \App\Support\StorefrontAssets::mediumUrl($path)
        ?? \App\Support\StorefrontAssets::url($path);
    $srcset = \App\Support\StorefrontAssets::srcset($path);
@endphp

@if ($src)
    <img
        src="{{ $src }}"
        @if ($srcset) srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
        alt="{{ $alt }}"
        loading="lazy"
        decoding="async"
        {{ $attributes }}
    >
@endif
