@php
    $money = fn (float $value): string => '৳ '.number_format($value, 0);
@endphp

<div>
    <div class="mb-2">
        <h1 class="font-serif text-3xl font-semibold">All orders with costs</h1>
        <p class="mt-1 text-sm text-[#8C8474]">
            Revenue, direct costs, and contribution P/L. Double-click COGS, packaging, or courier to edit.
        </p>
    </div>

    <x-admin.analytics-subnav active="orders-costs" />

    <div class="mb-4 flex flex-wrap gap-3">
        <input type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Search order #, name, phone…"
            class="min-w-[14rem] flex-1 rounded-lg border border-[#E0D6C2] bg-white px-4 py-2 text-sm">
        <select wire:model.live="status" class="rounded-lg border border-[#E0D6C2] bg-white px-4 py-2 text-sm">
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-hidden rounded-xl border border-[#EFE7D6] bg-white">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">Order</th>
                        <th class="px-4 py-3 font-medium text-right">Revenue</th>
                        <th class="px-4 py-3 font-medium text-right" title="Double-click to edit">COGS</th>
                        <th class="px-4 py-3 font-medium text-right" title="Double-click to edit">Packaging</th>
                        <th class="px-4 py-3 font-medium text-right" title="Double-click to edit">Courier</th>
                        <th class="px-4 py-3 font-medium text-right">COD fee</th>
                        <th class="px-4 py-3 font-medium text-right">Direct</th>
                        <th class="px-4 py-3 font-medium text-right">P/L</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($orders as $order)
                        @php
                            $econ = $economicsById[$order->id];
                            $isEditing = fn (string $field): bool => $editingOrderId === $order->id && $editingField === $field;
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

                            @foreach ([
                                'cogs' => $econ['cogs'],
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
                                        {{ $money($value) }}
                                    @endif
                                </td>
                            @endforeach

                            <td class="px-4 py-3 text-right tabular-nums text-[#6B6459]">{{ $money($econ['cod']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-[#6B6459]">{{ $money($econ['direct']) }}</td>
                            <td @class([
                                'px-4 py-3 text-right tabular-nums font-medium',
                                'text-emerald-700' => $econ['profit'] >= 0,
                                'text-rose-700' => $econ['profit'] < 0,
                            ])>
                                {{ $money($econ['profit']) }}
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
</div>
