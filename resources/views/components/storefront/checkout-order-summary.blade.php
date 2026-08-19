@props([
    'pricing',
    'selectedArea' => null,
    'selectedCity' => null,
    'itemCount' => 0,
    'compact' => false,
    'nested' => false,
])

<div {{ $attributes->class([
    'rounded-xl border border-[#EFE7D6] bg-white p-6' => ! $nested,
    'border-t border-[#E7DFCF] pt-4' => $nested,
]) }}>
    <h2 class="font-semibold mb-4">{{ __('storefront.order_summary') }}</h2>
    <div class="space-y-2 text-sm">
        <div class="flex justify-between">
            <span class="text-[#6B6459]">{{ __('storefront.subtotal') }}</span>
            <span>&#2547; {{ number_format($pricing->subtotal, 0) }}</span>
        </div>
        <div class="flex justify-between">
            <span class="text-[#6B6459]">{{ __('storefront.delivery_charge') }}</span>
            <span>
                @if ($pricing->deliveryCharge <= 0)
                    <span class="text-emerald-700">{{ __('storefront.free') }}</span>
                @else
                    &#2547; {{ number_format($pricing->deliveryCharge, 0) }}
                @endif
            </span>
        </div>
        @foreach ($pricing->adjustmentLines as $line)
            <div class="flex justify-between text-emerald-700">
                <span>
                    @if ($line['type'] === 'coupon')
                        {{ __('storefront.adjustment_coupon', ['code' => $line['label']]) }}
                    @elseif ($line['type'] === 'discount')
                        {{ __('storefront.adjustment_discount', ['label' => $line['label']]) }}
                    @else
                        {{ $line['label'] }}
                    @endif
                </span>
                <span>− &#2547; {{ number_format($line['amount'], 0) }}</span>
            </div>
        @endforeach
        @if ($pricing->discount > 0 && $pricing->adjustmentLines === [])
            <div class="flex justify-between text-emerald-700">
                <span>{{ __('storefront.discount') }}</span>
                <span>− &#2547; {{ number_format($pricing->discount, 0) }}</span>
            </div>
        @endif
    </div>
    <div class="border-t border-[#E7DFCF] mt-4 pt-4 flex justify-between font-semibold text-lg">
        <span>{{ __('storefront.total_cod') }}</span>
        <span>&#2547; {{ number_format($pricing->total, 0) }}</span>
    </div>
    @unless ($compact)
        <p class="mt-4 text-xs text-[#8C8474] leading-relaxed">
            @if ($selectedArea)
                {{ __('storefront.delivery_for_area', [
                    'area' => $selectedArea->name,
                    'upto5' => number_format($selectedArea->delivery_charge_upto_5, 0),
                    'over5' => number_format($selectedArea->delivery_charge_over_5, 0),
                ]) }}
                @if ($itemCount > 0)
                    {{ __('storefront.your_cart_items', ['count' => $itemCount]) }}
                @endif
            @elseif ($selectedCity)
                {{ __('storefront.select_area_for_charge') }}
            @else
                {{ __('storefront.select_city_area') }}
            @endif
        </p>
        @if ($selectedCity || $selectedArea)
            <div class="mt-4 rounded-lg bg-[#FAF6EF] p-3 text-xs text-[#6B6459] leading-relaxed">
                <p class="font-medium mb-1">{{ __('storefront.delivery_guide_title') }}</p>
                <p>{{ __('storefront.delivery_guide_dhaka') }}</p>
                <p>{{ __('storefront.delivery_guide_outside') }}</p>
            </div>
        @endif
    @endunless
</div>
