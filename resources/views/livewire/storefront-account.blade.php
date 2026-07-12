<x-storefront.shell>
    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="font-serif text-3xl font-semibold mb-8">My Account</h1>

        <div class="grid lg:grid-cols-4 gap-8 items-start">
            <div class="lg:col-span-1">
                <x-storefront.account-nav />
            </div>

            <div class="lg:col-span-3 space-y-6">
                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                    <h2 class="font-semibold text-lg mb-4">Welcome, {{ $user->name }}</h2>
                    <dl class="grid sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-[#8C8474]">Mobile</dt>
                            <dd class="font-medium">{{ $user->phone }}</dd>
                        </div>
                        <div>
                            <dt class="text-[#8C8474]">Email</dt>
                            <dd class="font-medium">{{ $user->email }}</dd>
                        </div>
                    </dl>
                    <div class="mt-6 flex flex-wrap gap-3">
                        <a href="{{ route('account.profile') }}" wire:navigate
                            class="rounded-full border border-[#C9A227] px-5 py-2 text-sm font-medium text-[#C9A227] hover:bg-[#FAF6EF]">
                            Edit Profile
                        </a>
                        <a href="{{ route('account.orders') }}" wire:navigate
                            class="rounded-full border border-[#E0D6C2] px-5 py-2 text-sm hover:bg-[#FAF6EF]">
                            View All Orders
                        </a>
                    </div>
                </div>

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                    <h2 class="font-semibold text-lg mb-4">Recent Orders</h2>
                    @if ($recentOrders->isEmpty())
                        <p class="text-sm text-[#6B6459]">You have not placed any orders yet.</p>
                        <a href="{{ route('home') }}" wire:navigate class="inline-block mt-4 text-sm text-[#C9A227] hover:underline">Start shopping</a>
                    @else
                        <div class="space-y-3 text-sm">
                            @foreach ($recentOrders as $order)
                                <a href="{{ route('account.orders.show', $order) }}" wire:navigate
                                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-[#E7DFCF] px-4 py-3 hover:bg-[#FAF6EF] transition">
                                    <div>
                                        <span class="font-medium">#{{ $order->order_number }}</span>
                                        <span class="text-[#8C8474] ml-2">{{ $order->placed_at?->format('d M Y') }}</span>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <span class="capitalize text-[#6B6459]">{{ $order->status }}</span>
                                        <span class="font-medium">&#2547; {{ number_format($order->total, 0) }}</span>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-storefront.shell>
