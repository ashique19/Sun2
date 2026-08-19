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

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                    <h2 class="font-semibold text-lg mb-4">{{ __('storefront.welcome_user', ['name' => $user->name]) }}</h2>
                    <dl class="grid sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-[#8C8474]">{{ __('storefront.mobile') }}</dt>
                            <dd class="font-medium">{{ $user->phone }}</dd>
                        </div>
                        <div>
                            <dt class="text-[#8C8474]">{{ __('storefront.email_label') }}</dt>
                            <dd class="font-medium">{{ $user->email }}</dd>
                        </div>
                    </dl>
                    <div class="mt-6 flex flex-wrap gap-3">
                        <a href="{{ route('account.profile') }}" wire:navigate
                            class="rounded-full border border-[#C9A227] px-5 py-2 text-sm font-medium text-[#C9A227] hover:bg-[#FAF6EF]">
                            {{ __('storefront.edit_profile') }}
                        </a>
                        <a href="{{ route('account.orders') }}" wire:navigate
                            class="rounded-full border border-[#E0D6C2] px-5 py-2 text-sm hover:bg-[#FAF6EF]">
                            {{ __('storefront.view_all_orders') }}
                        </a>
                    </div>
                </div>

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                    <h2 class="font-semibold text-lg mb-4">{{ __('storefront.recent_orders') }}</h2>
                    @if ($recentOrders->isEmpty())
                        <p class="text-sm text-[#6B6459]">{{ __('storefront.no_orders_yet') }}</p>
                        <a href="{{ route('home') }}" wire:navigate class="inline-block mt-4 text-sm text-[#C9A227] hover:underline">{{ __('storefront.start_shopping') }}</a>
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
