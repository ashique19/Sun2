<x-storefront.shell>
    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="font-serif text-3xl font-semibold mb-8">{{ __('storefront.my_account') }}</h1>

        <div class="grid lg:grid-cols-4 gap-4 lg:gap-8 items-start">
            <div class="min-w-0 lg:col-span-1">
                <x-storefront.account-nav />
            </div>

            <div class="lg:col-span-3 space-y-6">
                @if ($passwordStatusMessage)
                    <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3">{{ $passwordStatusMessage }}</div>
                @endif

                @if ($needsPassword)
                    <div class="rounded-xl border border-[#C9A227]/40 bg-[#FAF6EF] p-6">
                        <h2 class="font-semibold text-lg mb-1">{{ __('storefront.set_password_title') }}</h2>
                        <p class="text-sm text-[#6B6459] mb-4">{{ __('storefront.set_password_subtitle') }}</p>

                        <form wire:submit="setPassword" class="grid sm:grid-cols-2 gap-4 max-w-2xl">
                            <div>
                                <label class="block text-sm font-medium mb-1">{{ __('storefront.new_password') }}</label>
                                <input type="password" wire:model="password"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                @error('password') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">{{ __('storefront.confirm_new_password') }}</label>
                                <input type="password" wire:model="password_confirmation"
                                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                            </div>
                            <div class="sm:col-span-2">
                                <button type="submit"
                                    class="rounded-full bg-[#C9A227] px-8 py-3 text-sm font-semibold text-white hover:bg-[#b8931f] transition">
                                    {{ __('storefront.set_password_btn') }}
                                </button>
                            </div>
                        </form>
                    </div>
                @endif

                <h2 class="font-serif text-2xl font-semibold">{{ __('storefront.welcome_user', ['name' => $user->name]) }}</h2>

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <h2 class="font-semibold text-lg">{{ __('storefront.active_orders') }}</h2>
                        <a href="{{ route('account.orders') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">
                            {{ __('storefront.view_all_orders') }}
                        </a>
                    </div>
                    @if ($activeOrders->isEmpty())
                        <p class="text-sm text-[#6B6459]">{{ __('storefront.no_active_orders') }}</p>
                        <a href="{{ route('home') }}" wire:navigate class="inline-block mt-4 text-sm text-[#C9A227] hover:underline">{{ __('storefront.start_shopping') }}</a>
                    @else
                        <div class="space-y-3 text-sm">
                            @foreach ($activeOrders as $order)
                                <a href="{{ route('account.orders.show', $order) }}" wire:navigate
                                    class="block rounded-lg border border-[#E7DFCF] px-4 py-3 hover:bg-[#FAF6EF] transition">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <span class="font-medium">#{{ $order->order_number }}</span>
                                            <span class="text-[#8C8474] ml-2">{{ $order->placed_at?->format('d M Y') }}</span>
                                        </div>
                                        <span class="font-medium">&#2547; {{ number_format($order->total, 0) }}</span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <x-storefront.order-status-badge :order="$order" />
                                        @if ($order->status === 'dispatched' && $order->courier_tracker)
                                            <span class="text-xs text-[#8C8474]">{{ __('storefront.tracking_code') }}: {{ $order->courier_tracker }}</span>
                                        @endif
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
