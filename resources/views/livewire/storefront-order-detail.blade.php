<x-storefront.shell>
    <div class="mx-auto max-w-6xl px-4 py-8">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-8">
            <h1 class="font-serif text-3xl font-semibold">Order #{{ $order->order_number }}</h1>
            <a href="{{ route('account.orders') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Back to orders</a>
        </div>

        <div class="grid lg:grid-cols-4 gap-8 items-start">
            <div class="lg:col-span-1">
                <x-storefront.account-nav />
            </div>

            <div class="lg:col-span-3 space-y-6">
                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6 text-sm space-y-3">
                    <div class="flex justify-between">
                        <span class="text-[#8C8474]">Status</span>
                        <span class="capitalize font-medium">{{ $order->status }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[#8C8474]">Placed</span>
                        <span>{{ $order->placed_at?->format('d M Y, h:i A') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[#8C8474]">Payment</span>
                        <span class="uppercase">{{ $order->payment_method }} — {{ ucfirst($order->payment_status) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[#8C8474]">Mobile</span>
                        <span>{{ $order->phone }}</span>
                    </div>
                    <div class="flex justify-between gap-4">
                        <span class="text-[#8C8474] shrink-0">Delivery</span>
                        <span class="text-right">{{ $order->address }}@if($order->area), {{ $order->area }}@endif, {{ $order->city }}</span>
                    </div>
                </div>

                <div class="rounded-xl border border-[#EFE7D6] bg-white p-6">
                    <h2 class="font-semibold mb-4">Items</h2>
                    <div class="space-y-3 text-sm">
                        @foreach ($order->items as $item)
                            <div class="flex items-center justify-between gap-4">
                                <div class="flex items-center gap-3 min-w-0">
                                    <x-order-product-thumb :item="$item" size="md" />
                                    <div class="min-w-0">
                                        <p class="font-medium truncate">{{ $item->displayName() }}</p>
                                        <p class="text-[#8C8474] {{ $item->quantity > 1 ? 'text-rose-600 font-medium' : '' }}">
                                            Qty: {{ $item->quantity }}
                                        </p>
                                    </div>
                                </div>
                                <span class="shrink-0 font-medium">&#2547; {{ number_format($item->line_total, 0) }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="border-t border-[#E7DFCF] mt-4 pt-4 space-y-4">
                        <div>
                            <h3 class="font-semibold mb-3">Charges</h3>
                            @php
                                $itemCount = $order->items->sum('quantity');
                            @endphp
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between gap-4">
                                    <span class="text-[#6B6459]">
                                        Subtotal
                                        @if ($itemCount > 0)
                                            <span class="text-[#8C8474]">({{ $itemCount }} {{ str('item')->plural($itemCount) }})</span>
                                        @endif
                                    </span>
                                    <span>&#2547; {{ number_format($order->subtotal, 0) }}</span>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <span class="text-[#6B6459]">Delivery charge</span>
                                    <span>
                                        @if ((float) $order->delivery_charge <= 0)
                                            <span class="text-emerald-700">Free</span>
                                        @else
                                            &#2547; {{ number_format($order->delivery_charge, 0) }}
                                        @endif
                                    </span>
                                </div>
                                @if ($order->charge > 0)
                                    <div class="flex justify-between gap-4">
                                        <span class="text-[#6B6459]">Charge</span>
                                        <span>&#2547; {{ number_format($order->charge, 0) }}</span>
                                    </div>
                                @endif
                                @if ($order->discount > 0)
                                    <div class="flex justify-between gap-4 text-emerald-700">
                                        <span>
                                            @if ($order->coupon)
                                                Coupon discount ({{ $order->coupon->code }})
                                            @else
                                                Discount
                                            @endif
                                        </span>
                                        <span>− &#2547; {{ number_format($order->discount, 0) }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if ($order->coupon)
                            <div class="rounded-lg border border-emerald-200 bg-emerald-50/70 p-4 text-sm space-y-2">
                                <p class="font-medium text-emerald-900">Coupon applied</p>
                                <div class="flex justify-between gap-4">
                                    <span class="text-emerald-800">Code</span>
                                    <span class="font-mono font-semibold tracking-wide text-emerald-900">{{ $order->coupon->code }}</span>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <span class="text-emerald-800">Offer</span>
                                    <span class="text-emerald-900">{{ $order->coupon->summaryLabel() }}</span>
                                </div>
                                @if ((float) $order->coupon->min_order > 0)
                                    <div class="flex justify-between gap-4">
                                        <span class="text-emerald-800">Minimum order</span>
                                        <span class="text-emerald-900">&#2547; {{ number_format($order->coupon->min_order, 0) }}</span>
                                    </div>
                                @endif
                                <div class="flex justify-between gap-4">
                                    <span class="text-emerald-800">You saved</span>
                                    <span class="font-medium text-emerald-900">&#2547; {{ number_format($order->discount, 0) }}</span>
                                </div>
                            </div>
                        @endif

                        <div class="border-t border-[#E7DFCF] pt-4 space-y-2 text-sm">
                            <div class="flex justify-between font-semibold text-base">
                                <span>Total{{ $order->payment_method === 'cod' ? ' (COD)' : '' }}</span>
                                <span>&#2547; {{ number_format($order->total, 0) }}</span>
                            </div>
                            @if ($order->payment_status === 'partial')
                                <div class="flex justify-between text-emerald-700">
                                    <span>Paid</span>
                                    <span>&#2547; {{ number_format($order->paid_amount, 0) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-[#6B6459]">Balance due</span>
                                    <span>&#2547; {{ number_format($order->due_amount, 0) }}</span>
                                </div>
                            @elseif ($order->payment_status === 'paid' && (float) $order->paid_amount > 0)
                                <div class="flex justify-between text-emerald-700">
                                    <span>Paid in full</span>
                                    <span>&#2547; {{ number_format($order->paid_amount, 0) }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-storefront.shell>
