@props([
    'order',
    'courierStatus' => null,
    'trackingEvents' => [],
    'statusProgress' => [],
])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-[#EFE7D6] bg-white p-6 text-sm space-y-4']) }}>
    <div>
        <h2 class="font-semibold text-lg">{{ __('storefront.delivery_tracking') }}</h2>
        <p class="text-xs text-[#8C8474] mt-1">{{ __('storefront.delivery_tracking_hint') }}</p>
    </div>

    <dl class="grid sm:grid-cols-2 gap-3">
        @if ($order->courier)
            <div>
                <dt class="text-[#8C8474]">{{ __('storefront.courier_name') }}</dt>
                <dd class="font-medium">{{ $order->courier->name }}</dd>
            </div>
        @endif
        @if ($order->courier_tracker)
            <div>
                <dt class="text-[#8C8474]">{{ __('storefront.tracking_code') }}</dt>
                <dd class="font-medium break-all">{{ $order->courier_tracker }}</dd>
            </div>
        @endif
        @if ($order->dispatch_date)
            <div>
                <dt class="text-[#8C8474]">{{ __('storefront.dispatch_date') }}</dt>
                <dd class="font-medium">{{ $order->dispatch_date->format('d M Y') }}</dd>
            </div>
        @endif
        @if ($order->expected_delivery_date)
            <div>
                <dt class="text-[#8C8474]">{{ __('storefront.expected_delivery_date') }}</dt>
                <dd class="font-medium">{{ $order->expected_delivery_date->format('d M Y') }}</dd>
            </div>
        @endif
        @if ($order->actual_delivery_date)
            <div>
                <dt class="text-[#8C8474]">{{ __('storefront.delivered_on') }}</dt>
                <dd class="font-medium">{{ $order->actual_delivery_date->format('d M Y') }}</dd>
            </div>
        @endif
        @if ($courierStatus)
            <div>
                <dt class="text-[#8C8474]">{{ __('storefront.courier_status') }}</dt>
                <dd class="font-medium capitalize">{{ str_replace('_', ' ', $courierStatus) }}</dd>
            </div>
        @endif
    </dl>

    @if ($statusProgress->isNotEmpty())
        <div>
            <h3 class="font-medium mb-2">{{ __('storefront.order_progress') }}</h3>
            <ol class="space-y-2">
                @foreach ($statusProgress as $entry)
                    <li class="flex items-start gap-3 text-sm">
                        <span class="w-24 shrink-0 tabular-nums text-[#8C8474]">{{ $entry->created_at?->format('d M Y') }}</span>
                        <span class="font-medium">{{ \App\Models\Order::customerStatusLabelFor($entry->status) }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    <div>
        <h3 class="font-medium mb-2">{{ __('storefront.tracking_updates') }}</h3>
        @if ($trackingEvents === [])
            <p class="text-[#8C8474] text-sm">{{ __('storefront.no_tracking_yet') }}</p>
        @else
            <ul class="space-y-2">
                @foreach ($trackingEvents as $event)
                    <li class="flex gap-3 text-sm leading-snug">
                        <span class="w-24 shrink-0 tabular-nums text-[#5B8DEF]">{{ $event['at'] }}</span>
                        <span class="min-w-0 break-words text-[#1E1E1E]">{{ $event['message'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
