<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="font-serif text-2xl font-semibold sm:text-3xl">
            {{ $segment === 'history' ? 'Order history' : 'Orders in progress' }}
        </h1>
        <a href="{{ route('reseller.orders.create') }}" wire:navigate
            class="rounded-lg bg-[#C9A227] px-4 py-2 text-sm font-medium text-white hover:bg-[#b8931f]">Create order</a>
    </div>

    <div class="mb-4 flex gap-2">
        <a href="{{ route('reseller.orders.progress') }}" wire:navigate
            class="rounded-full px-4 py-1.5 text-sm border {{ $segment === 'progress' ? 'border-[#C9A227] bg-[#C9A227] text-white' : 'border-[#E0D6C2] bg-white text-[#6B6459]' }}">In progress</a>
        <a href="{{ route('reseller.orders.history') }}" wire:navigate
            class="rounded-full px-4 py-1.5 text-sm border {{ $segment === 'history' ? 'border-[#C9A227] bg-[#C9A227] text-white' : 'border-[#E0D6C2] bg-white text-[#6B6459]' }}">History</a>
    </div>

    <div class="space-y-3">
        @forelse ($orders as $order)
            <a href="{{ route('reseller.orders.show', $order) }}" wire:navigate
                class="block rounded-xl border border-[#EFE7D6] bg-white p-4 hover:border-[#C9A227]/50">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-medium">#{{ $order->order_number }} · {{ $order->name }}</p>
                        <p class="text-sm text-[#8C8474]">{{ $order->phone }}</p>
                        <p class="mt-1 text-xs text-[#8C8474]">{{ $order->placed_at?->format('d M Y, h:i A') }} · <span class="capitalize">{{ $order->status }}</span></p>
                    </div>
                    <span class="shrink-0 font-semibold tabular-nums">&#2547; {{ number_format($order->total, 0) }}</span>
                </div>
            </a>
        @empty
            <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-8 text-center text-sm text-[#8C8474]">No orders in this list.</div>
        @endforelse
    </div>

    @if ($orders->hasPages())
        <div class="mt-4 rounded-xl border border-[#EFE7D6] bg-white px-4 py-3">{{ $orders->links() }}</div>
    @endif
</div>
