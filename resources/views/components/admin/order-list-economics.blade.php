@props([
    'order',
    'showDue' => false,
])

@php($money = $order->moneyTotals())
@php($netRevenue = $money->netRevenue)
@php($isCancelReturn = in_array($order->status, ['cancelled', 'returned'], true))
@php($hasPartialReturns = $order->items->contains(fn ($item) => (int) ($item->returned_quantity ?? 0) > 0))
@php($chargeLines = $order->adjustments->where('type', 'charge'))
@php($discountLines = $order->adjustments->whereIn('type', ['discount', 'coupon']))
@php($hasBreakdown = $isCancelReturn
    || $hasPartialReturns
    || $money->subtotal > 0
    || $money->cogs > 0
    || $money->charges > 0
    || $money->discounts > 0
    || $money->deliveryCharge > 0
    || $money->courierCharge > 0
    || $money->packagingCost > 0)

<div {{ $attributes->merge(['class' => 'shrink-0 text-right']) }}>
    <p class="text-[11px] uppercase tracking-wide text-[#8C8474]">Collect</p>
    <p class="text-sm font-semibold tabular-nums text-[#1E1E1E]">&#2547; {{ number_format($order->collectableAmount(), 0) }}</p>
    @if ((float) $order->paid_amount > 0 && round((float) $order->collectableAmount(), 2) !== round((float) $order->total, 2))
        <p class="text-[11px] text-[#8C8474]">Bill &#2547;{{ number_format($order->total, 0) }}</p>
    @endif
    <p class="mt-1 text-[11px] text-[#8C8474]">
        Net
        <span @class(['tabular-nums font-medium', 'text-rose-600' => $netRevenue < 0, 'text-[#6B6459]' => $netRevenue >= 0])>
            &#2547;{{ number_format($netRevenue, 0) }}
        </span>
    </p>

    @if ($showDue && (float) $order->due_amount > 0 && (float) $order->paid_amount > 0)
        <p class="text-[11px] text-[#8C8474]">Due &#2547;{{ number_format($order->due_amount, 0) }}</p>
    @endif

    @if ($hasBreakdown)
        <div x-data="{ open: false }" class="mt-1 inline-block text-left">
            <button type="button"
                @click="open = ! open"
                :aria-expanded="open.toString()"
                class="inline-flex items-center gap-0.5 text-[10px] font-medium text-[#8C8474] hover:text-[#C9A227]">
                <span x-text="open ? 'Hide' : 'Breakdown'"></span>
                <svg class="h-3 w-3 shrink-0 transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
            <div x-show="open"
                x-cloak
                class="mt-1.5 w-48 rounded-lg border border-[#EFE7D6] bg-[#FAF6EF]/80 px-2.5 py-2 text-[10px] leading-snug text-[#6B6459] shadow-sm">
                @if ($isCancelReturn)
                    <p class="mb-1 font-medium text-[#8C8474]">After cancel / return</p>
                    <div class="flex justify-between gap-2 tabular-nums">
                        <span>Cash collected</span>
                        <span>&#2547;{{ number_format($money->remittanceBase, 0) }}</span>
                    </div>
                    @if ($money->courierCharge > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>− Courier</span>
                            <span>&#2547;{{ number_format($money->courierCharge, 0) }}</span>
                        </div>
                    @endif
                    @if ($money->packagingCost > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>− Packaging</span>
                            <span>&#2547;{{ number_format($money->packagingCost, 0) }}</span>
                        </div>
                    @endif
                    @if ($money->codCharge > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>− COD %</span>
                            <span>&#2547;{{ number_format($money->codCharge, 2) }}</span>
                        </div>
                    @endif
                @elseif ($hasPartialReturns)
                    <p class="mb-1 font-medium text-[#8C8474]">After partial delivery</p>
                    <div class="flex justify-between gap-2 tabular-nums">
                        <span>Merchandise</span>
                        <span>&#2547;{{ number_format($money->subtotal, 0) }}</span>
                    </div>
                    @foreach ($discountLines as $adj)
                        <div class="flex justify-between gap-2 tabular-nums text-emerald-700">
                            <span class="min-w-0 truncate" title="{{ $adj->label }}">− {{ $adj->label }}</span>
                            <span class="shrink-0">&#2547;{{ number_format($adj->amount, 0) }}</span>
                        </div>
                    @endforeach
                    @if ($money->cogs > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>− COGS kept</span>
                            <span>&#2547;{{ number_format($money->cogs, 0) }}</span>
                        </div>
                    @endif
                    @if ($money->deliveryCharge > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>+ Delivery</span>
                            <span>&#2547;{{ number_format($money->deliveryCharge, 0) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between gap-2 tabular-nums">
                        <span>Collected</span>
                        <span>&#2547;{{ number_format($money->remittanceBase, 0) }}</span>
                    </div>
                    @if ($money->courierCharge > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>− Courier</span>
                            <span>&#2547;{{ number_format($money->courierCharge, 0) }}</span>
                        </div>
                    @endif
                    @if ($money->packagingCost > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>− Packaging</span>
                            <span>&#2547;{{ number_format($money->packagingCost, 0) }}</span>
                        </div>
                    @endif
                    @if ($money->codCharge > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>− COD %</span>
                            <span>&#2547;{{ number_format($money->codCharge, 2) }}</span>
                        </div>
                    @endif
                @else
                    <div class="flex justify-between gap-2 tabular-nums">
                        <span>Revenue</span>
                        <span>&#2547;{{ number_format($money->subtotal, 0) }}</span>
                    </div>
                    @if ($money->cogs > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>− COGS</span>
                            <span>&#2547;{{ number_format($money->cogs, 0) }}</span>
                        </div>
                    @endif
                    @foreach ($chargeLines as $adj)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span class="min-w-0 truncate" title="{{ $adj->label }}">+ {{ $adj->label }}</span>
                            <span class="shrink-0">&#2547;{{ number_format($adj->amount, 0) }}</span>
                        </div>
                    @endforeach
                    @if ($chargeLines->isEmpty() && (float) $order->charge > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>+ Charges</span>
                            <span>&#2547;{{ number_format($order->charge, 0) }}</span>
                        </div>
                    @endif
                    @foreach ($discountLines as $adj)
                        <div class="flex justify-between gap-2 tabular-nums text-emerald-700">
                            <span class="min-w-0 truncate" title="{{ $adj->label }}">− {{ $adj->label }}</span>
                            <span class="shrink-0">&#2547;{{ number_format($adj->amount, 0) }}</span>
                        </div>
                    @endforeach
                    @if ($discountLines->isEmpty() && (float) $order->discount > 0)
                        <div class="flex justify-between gap-2 tabular-nums text-emerald-700">
                            <span>− Discounts</span>
                            <span>&#2547;{{ number_format($order->discount, 0) }}</span>
                        </div>
                    @endif
                    @if ($money->deliveryCharge > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>+ Cust. delivery</span>
                            <span>&#2547;{{ number_format($money->deliveryCharge, 0) }}</span>
                        </div>
                    @endif
                    @if ($money->courierCharge > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>− Courier cost</span>
                            <span>&#2547;{{ number_format($money->courierCharge, 0) }}</span>
                        </div>
                    @endif
                    @if ($money->packagingCost > 0)
                        <div class="flex justify-between gap-2 tabular-nums">
                            <span>− Packaging</span>
                            <span>&#2547;{{ number_format($money->packagingCost, 0) }}</span>
                        </div>
                    @endif
                @endif
                <div class="mt-1 flex justify-between gap-2 border-t border-[#E7DFCF] pt-1 font-semibold tabular-nums text-[#1E1E1E]">
                    <span>Net</span>
                    <span @class(['text-rose-600' => $netRevenue < 0])>&#2547;{{ number_format($netRevenue, 0) }}</span>
                </div>
            </div>
        </div>
    @endif
</div>
