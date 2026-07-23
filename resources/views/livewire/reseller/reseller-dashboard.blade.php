<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-serif text-2xl font-semibold sm:text-3xl">Dashboard</h1>
            <p class="text-sm text-[#8C8474]">{{ auth()->user()->name }} · {{ auth()->user()->phone }}</p>
        </div>
        <a href="{{ route('reseller.orders.create') }}" wire:navigate
            class="rounded-lg bg-[#C9A227] px-4 py-2 text-sm font-medium text-white hover:bg-[#b8931f]">Create order</a>
    </div>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-[#8C8474]">Account balance</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">&#2547; {{ number_format($balance, 0) }}</p>
            <a href="{{ route('reseller.wallet') }}" wire:navigate class="mt-2 inline-block text-xs text-[#C9A227] hover:underline">Wallet &amp; payouts</a>
        </div>
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-[#8C8474]">Pending commission</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">&#2547; {{ number_format($pendingCommission, 0) }}</p>
            <p class="mt-1 text-xs text-[#8C8474]">Credited after delivery</p>
        </div>
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-[#8C8474]">In progress</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $inProgress }}</p>
            <a href="{{ route('reseller.orders.progress') }}" wire:navigate class="mt-2 inline-block text-xs text-[#C9A227] hover:underline">View orders</a>
        </div>
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-[#8C8474]">Delivered</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $delivered }}</p>
            <a href="{{ route('reseller.orders.history') }}" wire:navigate class="mt-2 inline-block text-xs text-[#C9A227] hover:underline">History</a>
        </div>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
        <div class="mb-4 flex items-center justify-between gap-2">
            <h2 class="font-semibold">Recent orders</h2>
            <a href="{{ route('reseller.orders.progress') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">All</a>
        </div>
        @forelse ($recent as $order)
            <a href="{{ route('reseller.orders.show', $order) }}" wire:navigate
                class="flex items-center justify-between gap-3 border-t border-[#EFE7D6] py-3 first:border-t-0 first:pt-0">
                <div class="min-w-0">
                    <p class="font-medium">#{{ $order->order_number }} · {{ $order->name }}</p>
                    <p class="text-xs text-[#8C8474]">{{ $order->placed_at?->format('d M Y') }} · <span class="capitalize">{{ $order->status }}</span></p>
                </div>
                <span class="shrink-0 tabular-nums font-medium">&#2547; {{ number_format($order->total, 0) }}</span>
            </a>
        @empty
            <p class="text-sm text-[#8C8474]">No orders yet. Create one for a customer to get started.</p>
        @endforelse
    </div>
</div>
