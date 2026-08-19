@props(['order'])

<div {{ $attributes->class(['flex flex-wrap items-center justify-between gap-4 px-4 sm:px-6 py-4 hover:bg-[#FAF6EF]/60 transition text-sm']) }}>
    <div class="min-w-0 flex-1">
        <div class="font-medium">{{ __('storefront.order_number', ['number' => $order->order_number]) }}</div>
        <div class="text-[#8C8474]">
            {{ $order->placed_at?->format('d M Y, h:i A') }}
            &middot;
            {{ __('storefront.item_count', ['count' => $order->items_count ?? $order->items->count()]) }}
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <x-storefront.order-status-badge :order="$order" />
            @if ($order->showsCustomerCourierTracking() && $order->courier_tracker)
                <span class="text-xs text-[#8C8474]">{{ __('storefront.tracking_code') }}: {{ $order->courier_tracker }}</span>
            @endif
        </div>
    </div>
    @if ($order->relationLoaded('items') && $order->items->isNotEmpty())
        <div class="flex flex-wrap items-start gap-3">
            @foreach ($order->items as $item)
                <x-order-product-thumb :item="$item" show-quantity />
            @endforeach
        </div>
    @endif
    <div class="flex flex-col items-stretch sm:items-end gap-2 shrink-0 w-full sm:w-auto">
        <div class="font-medium text-right">&#2547; {{ number_format($order->total, 0) }}</div>
        <a href="{{ route('account.orders.show', $order) }}" wire:navigate
            class="inline-flex items-center justify-center rounded-full bg-[#C9A227] px-4 py-2 text-xs font-semibold text-white hover:bg-[#b8931f] transition whitespace-nowrap">
            {{ __('storefront.view_details_tracking_btn') }}
        </a>
    </div>
</div>
