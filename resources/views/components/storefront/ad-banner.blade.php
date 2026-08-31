@props([
    'slotKey',
    'width' => 728,
    'height' => 90,
    'format' => 'iframe',
    'invokeHost' => null,
])

@php
    $key = (string) $slotKey;
    $host = filled($invokeHost)
        ? (string) $invokeHost
        : (string) config('ads.invoke_host', 'www.highrevenueformat.com');
    $src = 'https://'.$host.'/'.$key.'/invoke.js';
@endphp

{{-- Bare creative only (no lab chrome). Alpine reinjects on Livewire navigate remounts. --}}
<div
    {{ $attributes->merge(['class' => 'storefront-ad-banner flex max-w-full justify-center overflow-x-auto']) }}
    data-ad-key="{{ $key }}"
    x-data
    x-init="
        window.atOptions = {
            key: @js($key),
            format: @js($format),
            height: {{ (int) $height }},
            width: {{ (int) $width }},
            params: {}
        };
        const existing = $el.querySelector('script[data-adsterra-invoke]');
        if (existing) {
            existing.remove();
        }
        const s = document.createElement('script');
        s.src = @js($src);
        s.async = true;
        s.dataset.adsterraInvoke = '1';
        $el.appendChild(s);
    "
></div>
