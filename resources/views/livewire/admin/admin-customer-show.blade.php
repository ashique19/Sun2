<div>
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div class="min-w-0">
            <a href="{{ route('admin.users.customers') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Customers</a>
            <h1 class="font-serif text-2xl sm:text-3xl font-semibold mt-1 sm:mt-2">{{ $displayName }}</h1>
            <p class="text-sm text-[#8C8474] mt-1">Customer profile</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.users.edit', $customer) }}" wire:navigate
                class="rounded-lg border border-[#E0D6C2] bg-white px-4 py-2 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                Edit user
            </a>
            <a href="{{ route('admin.orders.create', ['customer' => $customer->id]) }}" wire:navigate
                class="rounded-lg bg-[#C9A227] px-4 py-2 text-sm font-medium text-white hover:bg-[#b89220]">
                Create order
            </a>
        </div>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6 mb-6">
        <h2 class="font-semibold mb-4">Details</h2>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-[#8C8474]">Name</dt>
                <dd class="mt-0.5 font-medium text-[#1E1E1E]">{{ $displayName !== '' ? $displayName : '—' }}</dd>
            </div>
            <div>
                <dt class="text-[#8C8474]">Phone</dt>
                <dd class="mt-0.5 font-medium tabular-nums text-[#1E1E1E]">{{ $displayPhone !== '' ? $displayPhone : '—' }}</dd>
            </div>
            <div>
                <dt class="text-[#8C8474]">Area</dt>
                <dd class="mt-0.5 font-medium text-[#1E1E1E]">{{ $displayArea !== '' ? $displayArea : '—' }}</dd>
            </div>
            <div>
                <dt class="text-[#8C8474]">City</dt>
                <dd class="mt-0.5 font-medium text-[#1E1E1E]">{{ $displayCity !== '' ? $displayCity : '—' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-[#8C8474]">Address</dt>
                <dd class="mt-0.5 font-medium text-[#1E1E1E] break-words whitespace-pre-line">{{ $displayAddress !== '' ? $displayAddress : '—' }}</dd>
            </div>
        </dl>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
        <h2 class="font-semibold">Previous orders</h2>
        <a href="{{ route('admin.orders.create', ['customer' => $customer->id]) }}" wire:navigate
            class="rounded-lg bg-[#C9A227] px-4 py-2 text-sm font-medium text-white hover:bg-[#b89220]">
            Create order
        </a>
    </div>

    <div class="space-y-3">
        @forelse ($orders as $order)
            <article wire:key="customer-order-{{ $order->id }}" class="rounded-xl border border-[#EFE7D6] bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('admin.orders.show', $order) }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">
                                #{{ $order->order_number }}
                            </a>
                            <span class="rounded-full bg-[#FAF6EF] px-2 py-0.5 text-[11px] capitalize text-[#6B6459]">{{ $order->status }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-[#8C8474]">
                            {{ $order->placed_at?->format('d M Y') }}
                            @if ($order->courier?->name)
                                · {{ $order->courier->name }}
                            @endif
                        </p>
                        @php($areaCity = collect([$order->area, $order->city])->filter()->implode(', '))
                        @if ($areaCity !== '')
                            <p class="mt-1 text-xs text-[#8C8474]">{{ $areaCity }}</p>
                        @endif
                    </div>
                    <p class="shrink-0 text-sm font-semibold tabular-nums">&#2547; {{ number_format($order->total, 0) }}</p>
                </div>

                @if ($order->items->isNotEmpty())
                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                        @foreach ($order->items as $item)
                            <x-order-product-thumb
                                :item="$item"
                                size="md"
                                show-quantity
                            />
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-8 text-center text-[#8C8474]">
                No previous orders for this customer.
            </div>
        @endforelse

        @if ($orders->hasPages())
            <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-3">{{ $orders->links() }}</div>
        @endif
    </div>
</div>
