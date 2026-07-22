<x-storefront.shell>
    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="font-serif text-3xl font-semibold mb-8">{{ __('storefront.order_history') }}</h1>

        <div class="grid lg:grid-cols-4 gap-8 items-start">
            <div class="lg:col-span-1">
                <x-storefront.account-nav />
            </div>

            <div class="lg:col-span-3">
                <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
                    @if ($orders->isEmpty())
                        <div class="p-6 text-sm text-[#6B6459]">
                            {{ __('storefront.no_orders_yet') }}
                            <a href="{{ route('home') }}" wire:navigate class="text-[#C9A227] hover:underline">{{ __('storefront.browse_products') }}</a>
                        </div>
                    @else
                        <div class="divide-y divide-[#E7DFCF]">
                            @foreach ($orders as $order)
                                <a href="{{ route('account.orders.show', $order) }}" wire:navigate
                                    class="flex flex-wrap items-center justify-between gap-4 px-6 py-4 hover:bg-[#FAF6EF] transition text-sm">
                                    <div class="min-w-0">
                                        <div class="font-medium">{{ __('storefront.order_number', ['number' => $order->order_number]) }}</div>
                                        <div class="text-[#8C8474]">{{ $order->placed_at?->format('d M Y, h:i A') }} &middot; {{ __('storefront.item_count', ['count' => $order->items_count]) }}</div>
                                    </div>
                                    @if ($order->items->isNotEmpty())
                                        <div class="flex flex-wrap items-start gap-3">
                                            @foreach ($order->items as $item)
                                                <x-order-product-thumb :item="$item" show-quantity />
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="text-right shrink-0">
                                        <div class="font-medium">&#2547; {{ number_format($order->total, 0) }}</div>
                                        <div class="capitalize text-[#6B6459]">{{ $order->status }}</div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                        <div class="px-6 py-4 border-t border-[#E7DFCF]">
                            {{ $orders->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-storefront.shell>
