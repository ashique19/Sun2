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
    $w = (int) $width;
    $h = (int) $height;
@endphp

{{--
  Wide creatives (e.g. 728×90) must not expand the page on small screens.
  Outer: full-width scrollport. Inner: fixed creative size so overflow-x scrolls.
--}}
<div
    {{ $attributes->merge(['class' => 'storefront-ad-banner w-full min-w-0 max-w-full']) }}
    data-ad-key="{{ $key }}"
    data-ad-width="{{ $w }}"
    data-ad-height="{{ $h }}"
>
    <div
        class="storefront-ad-banner__scroll w-full min-w-0 max-w-full overflow-x-auto overscroll-x-contain [-webkit-overflow-scrolling:touch]"
        style="max-width: 100%;"
    >
        <div
            class="storefront-ad-banner__creative mx-auto flex shrink-0 justify-center"
            style="width: {{ $w }}px; min-width: {{ $w }}px; min-height: {{ $h }}px;"
            x-data
            x-init="
                window.atOptions = {
                    key: @js($key),
                    format: @js($format),
                    height: {{ $h }},
                    width: {{ $w }},
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
    </div>
</div>
