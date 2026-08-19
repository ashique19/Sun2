<x-storefront.shell>
    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="font-serif text-3xl font-semibold mb-8">{{ __('storefront.order_history') }}</h1>

        <div class="grid lg:grid-cols-4 gap-4 lg:gap-8 items-start">
            <div class="min-w-0 lg:col-span-1">
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
                                <x-storefront.order-list-row :order="$order" />
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
