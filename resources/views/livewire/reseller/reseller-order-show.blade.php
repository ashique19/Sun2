<div>
    <div class="mb-6">
        <a href="{{ route('reseller.orders.progress') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; Back</a>
        <h1 class="mt-2 font-serif text-2xl font-semibold sm:text-3xl">Order #{{ $order->order_number }}</h1>
        <p class="text-sm text-[#8C8474]">
            <span class="capitalize">{{ $order->status }}</span>
            · Placed {{ $order->placed_at?->format('d M Y, h:i A') }}
        </p>
    </div>

    <div class="space-y-4">
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
            <h2 class="mb-3 font-semibold">Customer</h2>
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-[#8C8474]">Name</dt><dd class="font-medium break-words">{{ $order->name }}</dd></div>
                <div><dt class="text-[#8C8474]">Phone</dt><dd class="font-medium">{{ $order->phone }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-[#8C8474]">Address</dt><dd class="break-words">{{ $order->address }}@if($order->area || $order->city), {{ collect([$order->area, $order->city])->filter()->implode(', ') }}@endif</dd></div>
            </dl>
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
            <h2 class="mb-3 font-semibold">Items</h2>
            <div class="space-y-3 text-sm">
                @foreach ($order->items as $item)
                    <div class="flex items-start justify-between gap-3 border-b border-[#EFE7D6] pb-3 last:border-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="font-medium break-words">{{ $item->displayName() }}</p>
                            <p class="text-[#8C8474]">Qty {{ $item->quantity }}
                                · Base &#2547; {{ number_format($item->base_price ?: $item->price, 0) }}
                                · Sell &#2547; {{ number_format($item->price, 0) }}
                            </p>
                            @php
                                $qty = (int) $item->quantity;
                                $base = (float) ($item->base_price ?: $item->price);
                                $sell = (float) $item->price;
                                $rate = (float) $item->commission_rate;
                                $est = ($rate + max(0, $sell - $base)) * $qty;
                            @endphp
                            <p class="text-xs text-emerald-700">
                                Commission
                                @if ((float) $item->commission_earned > 0)
                                    earned &#2547; {{ number_format($item->commission_earned, 0) }}
                                @else
                                    est. &#2547; {{ number_format($est, 0) }} (after delivery)
                                @endif
                            </p>
                        </div>
                        <span class="shrink-0 font-medium tabular-nums">&#2547; {{ number_format($item->line_total, 0) }}</span>
                    </div>
                @endforeach
            </div>
            <div class="mt-4 space-y-1 border-t border-[#E7DFCF] pt-3 text-sm">
                <div class="flex justify-between gap-3"><span class="text-[#6B6459]">Subtotal</span><span class="tabular-nums">&#2547; {{ number_format($order->subtotal, 0) }}</span></div>
                <div class="flex justify-between gap-3"><span class="text-[#6B6459]">Delivery</span><span class="tabular-nums">&#2547; {{ number_format($order->delivery_charge, 0) }}</span></div>
                <div class="flex justify-between gap-3 pt-1 text-base font-semibold"><span>Total</span><span class="tabular-nums">&#2547; {{ number_format($order->total, 0) }}</span></div>
            </div>
        </div>

        <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
            <h2 class="mb-3 font-semibold">Order audit log</h2>
            @if ($order->statusHistory->isEmpty())
                <p class="text-sm text-[#8C8474]">No history recorded.</p>
            @else
                <div class="space-y-4">
                    @foreach ($order->statusHistory as $entry)
                        <div class="border-l-2 border-[#C9A227] pl-4">
                            <p class="font-medium capitalize">{{ $entry->status }}</p>
                            <p class="text-xs text-[#8C8474]">{{ $entry->created_at?->format('d M Y, h:i A') }}
                                @if ($entry->changedBy) · {{ $entry->changedBy->name }} @endif
                            </p>
                            @if ($entry->note)
                                <p class="mt-1 text-sm text-[#6B6459]">{{ $entry->note }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
