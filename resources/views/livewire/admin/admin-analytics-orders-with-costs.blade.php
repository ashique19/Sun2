@php
    $money = fn (float $value): string => '৳ '.number_format($value, 0);
    $pctOfRevenue = function (float $value, float $revenue): ?float {
        if ($revenue <= 0) {
            return null;
        }

        return round(($value / $revenue) * 100, 1);
    };
@endphp

<div>
    <div class="mb-2">
        <h1 class="font-serif text-3xl font-semibold">All orders with costs</h1>
        <p class="mt-1 text-sm text-[#8C8474]">
            Revenue, direct costs, and contribution P/L. Double-click packaging or courier to edit.
            Double-click COGS to scale it, or open the product-cost modal when COGS is ৳0.
            Tiny % under each cost is share of revenue.
        </p>
    </div>

    <x-admin.analytics-subnav active="orders-costs" />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <input type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Search order #, name, phone…"
            class="min-w-[14rem] flex-1 rounded-lg border border-[#E0D6C2] bg-white px-4 py-2 text-sm">
        <select wire:model.live="status" class="rounded-lg border border-[#E0D6C2] bg-white px-4 py-2 text-sm">
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        @php
            $zeroFiltersActive = $zeroRevenue || $zeroCogs || $zeroPackaging || $zeroCourier
                || $zeroCod || $zeroDirect || $zeroProfit;
        @endphp
        @if ($zeroFiltersActive)
            <p class="text-sm tabular-nums text-[#6B6459]" data-zero-filter-count>
                <span class="font-semibold text-[#1E1E1E]">{{ number_format($orders->total()) }}</span>
                {{ $orders->total() === 1 ? 'order' : 'orders' }} match
            </p>
        @endif
    </div>

    <div class="overflow-hidden rounded-xl border border-[#EFE7D6] bg-white">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">Order</th>
                        <th class="px-4 py-3 font-medium text-right">Revenue</th>
                        <th class="px-4 py-3 font-medium text-right" title="Double-click to edit or fix missing product costs">COGS</th>
                        <th class="px-4 py-3 font-medium text-right" title="Double-click to edit">Packaging</th>
                        <th class="px-4 py-3 font-medium text-right" title="Double-click to edit">Courier</th>
                        <th class="px-4 py-3 font-medium text-right">COD fee</th>
                        <th class="px-4 py-3 font-medium text-right">Direct</th>
                        <th class="px-4 py-3 font-medium text-right">P/L</th>
                    </tr>
                    <tr class="border-t border-[#E7DFCF] text-[11px]">
                        <th class="px-4 py-2 font-normal text-[#8C8474]">
                            @if ($zeroFiltersActive)
                                <span class="tabular-nums font-semibold text-[#1E1E1E]" data-zero-filter-count-inline>
                                    {{ number_format($orders->total()) }}
                                </span>
                                <span class="block text-[10px] font-normal">
                                    {{ $orders->total() === 1 ? 'order' : 'orders' }}
                                </span>
                            @else
                                Filter 0
                            @endif
                        </th>
                        @foreach ([
                            'zeroRevenue' => 'Revenue is ৳0',
                            'zeroCogs' => 'COGS is ৳0',
                            'zeroPackaging' => 'Packaging is ৳0',
                            'zeroCourier' => 'Courier is ৳0',
                            'zeroCod' => 'COD fee is ৳0',
                            'zeroDirect' => 'Direct cost is ৳0',
                            'zeroProfit' => 'P/L is ৳0',
                        ] as $property => $label)
                            <th class="px-4 py-2 text-right font-normal">
                                <label class="inline-flex cursor-pointer items-center gap-1.5 text-[#6B6459]" title="{{ $label }}">
                                    <input type="checkbox"
                                        wire:model.live="{{ $property }}"
                                        class="h-3.5 w-3.5 rounded border-[#E0D6C2] text-[#C9A227] focus:ring-[#C9A227]/40"
                                        aria-label="{{ $label }}">
                                    <span>0</span>
                                </label>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($orders as $order)
                        @php
                            $econ = $economicsById[$order->id];
                            $revenue = (float) $econ['revenue'];
                            $isEditing = fn (string $field): bool => $editingOrderId === $order->id && $editingField === $field;
                            $cogsIsZero = (float) $econ['cogs'] < 0.01;
                        @endphp
                        <tr wire:key="order-costs-{{ $order->id }}" class="hover:bg-[#FAF6EF]/50">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.orders.show', $order) }}" wire:navigate
                                    class="font-medium text-[#C9A227] hover:underline">
                                    {{ $order->order_number }}
                                </a>
                                <div class="mt-0.5 text-xs text-[#8C8474]">
                                    {{ $order->name }}
                                    · {{ ucfirst($order->status) }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $money($econ['revenue']) }}</td>

                            {{-- COGS --}}
                            <td
                                class="px-4 py-3 text-right tabular-nums {{ $isEditing('cogs') ? '' : 'cursor-pointer select-none' }} {{ $cogsIsZero ? 'bg-rose-50/70' : '' }}"
                                title="{{ $cogsIsZero ? 'Double-click to set product costs' : 'Double-click to edit' }}"
                                @if (! $isEditing('cogs'))
                                    wire:dblclick="startInlineEdit({{ $order->id }}, 'cogs', '{{ (int) round($econ['cogs']) }}')"
                                @endif
                            >
                                @if ($isEditing('cogs'))
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        wire:model="editingValue"
                                        wire:keydown.enter.prevent="saveInlineEdit"
                                        wire:keydown.escape.prevent="cancelInlineEdit"
                                        wire:blur="saveInlineEdit"
                                        x-init="$nextTick(() => { $el.focus(); $el.select() })"
                                        class="ml-auto w-24 rounded-lg border border-[#C9A227] bg-white px-2 py-1 text-right text-sm tabular-nums shadow-sm focus:outline-none focus:ring-2 focus:ring-[#C9A227]/40"
                                        aria-label="Edit COGS"
                                    >
                                    @error('editingValue')
                                        <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                                    @enderror
                                @else
                                    <div>{{ $money($econ['cogs']) }}</div>
                                    @php $pct = $pctOfRevenue((float) $econ['cogs'], $revenue); @endphp
                                    <div class="text-[10px] leading-tight tabular-nums text-[#8C8474]">
                                        {{ $pct === null ? '—' : number_format($pct, 1).'%' }}
                                    </div>
                                @endif
                            </td>

                            @foreach ([
                                'packaging_cost' => $econ['packaging'],
                                'courier_charge' => $econ['courier'],
                            ] as $field => $value)
                                <td
                                    class="px-4 py-3 text-right tabular-nums {{ $isEditing($field) ? '' : 'cursor-pointer select-none' }}"
                                    title="Double-click to edit"
                                    @if (! $isEditing($field))
                                        wire:dblclick="startInlineEdit({{ $order->id }}, '{{ $field }}', '{{ (int) round($value) }}')"
                                    @endif
                                >
                                    @if ($isEditing($field))
                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            wire:model="editingValue"
                                            wire:keydown.enter.prevent="saveInlineEdit"
                                            wire:keydown.escape.prevent="cancelInlineEdit"
                                            wire:blur="saveInlineEdit"
                                            x-init="$nextTick(() => { $el.focus(); $el.select() })"
                                            class="ml-auto w-24 rounded-lg border border-[#C9A227] bg-white px-2 py-1 text-right text-sm tabular-nums shadow-sm focus:outline-none focus:ring-2 focus:ring-[#C9A227]/40"
                                            aria-label="Edit {{ str_replace('_', ' ', $field) }}"
                                        >
                                        @error('editingValue')
                                            <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                                        @enderror
                                    @else
                                        <div>{{ $money($value) }}</div>
                                        @php $pct = $pctOfRevenue((float) $value, $revenue); @endphp
                                        <div class="text-[10px] leading-tight tabular-nums text-[#8C8474]">
                                            {{ $pct === null ? '—' : number_format($pct, 1).'%' }}
                                        </div>
                                    @endif
                                </td>
                            @endforeach

                            <td class="px-4 py-3 text-right tabular-nums text-[#6B6459]">
                                <div>{{ $money($econ['cod']) }}</div>
                                @php $pct = $pctOfRevenue((float) $econ['cod'], $revenue); @endphp
                                <div class="text-[10px] leading-tight tabular-nums text-[#8C8474]">
                                    {{ $pct === null ? '—' : number_format($pct, 1).'%' }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-[#6B6459]">
                                <div>{{ $money($econ['direct']) }}</div>
                                @php $pct = $pctOfRevenue((float) $econ['direct'], $revenue); @endphp
                                <div class="text-[10px] leading-tight tabular-nums text-[#8C8474]">
                                    {{ $pct === null ? '—' : number_format($pct, 1).'%' }}
                                </div>
                            </td>
                            <td @class([
                                'px-4 py-3 text-right tabular-nums font-medium',
                                'text-emerald-700' => $econ['profit'] >= 0,
                                'text-rose-700' => $econ['profit'] < 0,
                            ])>
                                <div>{{ $money($econ['profit']) }}</div>
                                @php $pct = $pctOfRevenue((float) $econ['profit'], $revenue); @endphp
                                <div @class([
                                    'text-[10px] leading-tight tabular-nums font-normal',
                                    'text-emerald-700/80' => ($pct ?? 0) >= 0,
                                    'text-rose-700/80' => ($pct ?? 0) < 0,
                                    'text-[#8C8474]' => $pct === null,
                                ])>
                                    {{ $pct === null ? '—' : number_format($pct, 1).'%' }}
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-sm text-[#8C8474]">
                                No orders match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($orders->hasPages())
            <div class="border-t border-[#EFE7D6] px-4 py-3">
                {{ $orders->links() }}
            </div>
        @endif
    </div>

    @if ($cogsModalOpen)
        <div class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50 p-4 sm:items-center"
            wire:click.self="closeCogsModal"
            role="dialog"
            aria-modal="true"
            aria-label="Fix product costs for order {{ $cogsModalOrderNumber }}">
            <div class="max-h-[90vh] w-full max-w-2xl overflow-hidden rounded-xl border border-[#EFE7D6] bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-[#EFE7D6] px-4 py-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-[#1E1E1E]">Fix product costs</h2>
                        <p class="mt-0.5 text-xs text-[#8C8474]">
                            Order {{ $cogsModalOrderNumber }} · COGS is ৳0 because product costs were missing.
                            Saving updates the product and this order’s snapshots.
                        </p>
                    </div>
                    <button type="button"
                        wire:click="closeCogsModal"
                        class="rounded-lg border border-[#E0D6C2] px-2.5 py-1 text-xs text-[#6B6459] hover:border-[#C9A227]"
                        aria-label="Close">
                        Close
                    </button>
                </div>

                @if ($cogsModalMessage)
                    <div class="border-b border-emerald-100 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                        {{ $cogsModalMessage }}
                    </div>
                @endif

                <div class="max-h-[70vh] space-y-4 overflow-y-auto px-4 py-4">
                    @forelse ($cogsModalRows as $index => $row)
                        <div wire:key="{{ $row['key'] }}" class="rounded-xl border border-[#EFE7D6] p-3">
                            <div class="flex gap-3">
                                <div class="h-16 w-16 shrink-0 overflow-hidden rounded-lg border border-[#E7DFCF] bg-[#FAF6EF]">
                                    @if ($row['thumb'])
                                        <img src="{{ $row['thumb'] }}" alt="" class="h-full w-full object-cover">
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-[#1E1E1E]">{{ $row['name'] }}</p>
                                            <p class="mt-0.5 text-xs text-[#8C8474]">
                                                Qty {{ $row['qty'] }}
                                                @if ($row['has_materials'])
                                                    · BOM materials set main cost
                                                @endif
                                            </p>
                                        </div>
                                        @if ($row['edit_url'])
                                            <a href="{{ $row['edit_url'] }}" wire:navigate
                                                class="shrink-0 text-xs font-medium text-[#C9A227] hover:underline">
                                                Full product edit
                                            </a>
                                        @endif
                                    </div>

                                    <div class="mt-3 grid grid-cols-2 gap-2 sm:max-w-md">
                                        <div>
                                            <label class="mb-1 block text-[11px] font-medium text-[#6B6459]">Purchase / main</label>
                                            <input type="number" min="0" step="1"
                                                wire:model="cogsModalRows.{{ $index }}.purchase_price"
                                                @disabled($row['has_materials'])
                                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-sm tabular-nums disabled:bg-[#FAF6EF] disabled:text-[#8C8474]">
                                            @error('cogsModalRows.'.$index.'.purchase_price')
                                                <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-[11px] font-medium text-[#6B6459]">Other cost</label>
                                            <input type="number" min="0" step="1"
                                                wire:model="cogsModalRows.{{ $index }}.other_cost"
                                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-sm tabular-nums">
                                            @error('cogsModalRows.'.$index.'.other_cost')
                                                <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button type="button"
                                            wire:click="saveCogsModalRow({{ $index }})"
                                            class="rounded-lg bg-[#C9A227] px-3 py-1.5 text-xs font-medium text-white hover:bg-[#b89220]">
                                            Save{{ $row['product_id'] ? ' product + this order' : ' on this order' }}
                                        </button>
                                        @if ($row['product_id'])
                                            <button type="button"
                                                wire:click="syncCogsModalRowToAllOrders({{ $index }})"
                                                wire:confirm="Update this product’s costs and overwrite purchase/unit cost on every order line for this product?"
                                                class="rounded-lg border border-[#C9A227] px-3 py-1.5 text-xs font-medium text-[#C9A227] hover:bg-[#FAF6EF]">
                                                Sync to all orders with this product
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-[#8C8474]">This order has no line items.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
