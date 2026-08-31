@props([
    'slotKey',
    'label',
    'description' => null,
    'width' => 300,
    'height' => 250,
    'format' => 'iframe',
])

@php
    $key = filled($slotKey) ? (string) $slotKey : null;
@endphp

<div {{ $attributes->merge(['class' => 'ad-slot rounded-xl border border-[#E0D6C2] bg-white overflow-hidden']) }}>
    <div class="border-b border-[#EFE7D6] bg-[#FAF6EF] px-4 py-2 flex flex-wrap items-center justify-between gap-2">
        <div>
            <p class="text-sm font-semibold text-[#1E1E1E]">{{ $label }}</p>
            @if (filled($description))
                <p class="text-xs text-[#6B6459]">{{ $description }}</p>
            @endif
        </div>
        @if ($key)
            <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800">
                Live unit
            </span>
        @else
            <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">
                Placeholder
            </span>
        @endif
    </div>

    <div class="flex items-center justify-center p-4" style="min-height: {{ max(74, (int) $height + 24) }}px;">
        @if ($key)
            <div class="ad-slot__creative flex max-w-full justify-center overflow-x-auto">
                <script type="text/javascript">
                    atOptions = {
                        'key': @json($key),
                        'format': @json($format),
                        'height': {{ (int) $height }},
                        'width': {{ (int) $width }},
                        'params': {}
                    };
                </script>
                <script type="text/javascript" src="//www.highperformancedformats.com/{{ $key }}/invoke.js"></script>
            </div>
        @else
            <div
                class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-[#D9CEB8] bg-[#FAF6EF] text-center text-[#6B6459]"
                style="width: min(100%, {{ (int) $width }}px); min-height: {{ (int) $height }}px;"
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-[#8F7218]">{{ $label }}</p>
                <p class="mt-1 px-3 text-xs">{{ __('storefront.ads_lab_placeholder_hint') }}</p>
                <p class="mt-2 font-mono text-[10px] text-[#5C564C]">{{ (int) $width }}×{{ (int) $height }}</p>
            </div>
        @endif
    </div>
</div>
