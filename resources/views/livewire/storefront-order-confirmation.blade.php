<div>
    <x-storefront.announcement />
    <x-storefront.header />

    <div class="mx-auto max-w-3xl px-4 py-10 text-center">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-50 text-emerald-600 text-2xl mb-6">&#10003;</div>
        <h1 class="font-serif text-3xl font-semibold mb-2">Order Confirmed</h1>
        <p class="text-[#6B6459] mb-1">Thank you, {{ $order->name }}.</p>
        <p class="text-[#6B6459] mb-8">Your order <strong>#{{ $order->order_number }}</strong> has been placed successfully.</p>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 text-left text-sm space-y-3 mb-8">
            <div class="flex justify-between">
                <span class="text-[#8C8474]">Payment</span>
                <span>Cash on Delivery — &#2547; {{ number_format($order->total, 0) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-[#8C8474]">Mobile</span>
                <span>{{ $order->phone }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-[#8C8474] shrink-0">Address</span>
                <span class="text-right">{{ $order->address }}@if($order->area), {{ $order->area }}@endif, {{ $order->city }}</span>
            </div>
            <div class="border-t border-[#E7DFCF] pt-3 space-y-2">
                @foreach ($order->items as $item)
                    <div class="flex justify-between gap-4">
                        <span class="line-clamp-1">{{ $item->name }} &times; {{ $item->quantity }}</span>
                        <span class="shrink-0">&#2547; {{ number_format($item->line_total, 0) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="border-t border-[#E7DFCF] pt-3 flex justify-between font-semibold">
                <span>Total</span>
                <span>&#2547; {{ number_format($order->total, 0) }}</span>
            </div>
        </div>

        <a href="{{ route('home') }}" wire:navigate
           class="inline-block rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
            Continue Shopping
        </a>
        @auth
            <a href="{{ route('account.orders.show', $order) }}" wire:navigate
               class="inline-block ml-3 rounded-full border border-[#C9A227] px-8 py-3 text-sm font-semibold text-[#C9A227] hover:bg-[#FAF6EF] transition">
                View in Account
            </a>
        @endauth
    </div>

    <x-storefront.footer />
</div>
