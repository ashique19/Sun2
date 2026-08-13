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
        <button type="button"
            wire:click="openSettlementRepairModal"
            class="rounded-lg border border-[#C9A227] bg-white px-3 py-2 text-sm font-medium text-[#C9A227] hover:bg-[#FAF6EF]"
            title="Fill ৳0 courier on delivered/returned and settle unpaid delivered bills (100 per batch)">
            Repair settlement…
        </button>
        <button type="button"
            wire:click="openLegacyDescriptionModal"
            class="rounded-lg border border-[#1F4E79] bg-white px-3 py-2 text-sm font-medium text-[#1F4E79] hover:bg-[#FAF6EF]"
            title="Copy product descriptions from the legacy DB (100 per batch)">
            Copy legacy descriptions…
        </button>
        <button type="button"
            wire:click="openCalculationAuditModal"
            class="rounded-lg border border-[#1E1E1E] bg-white px-3 py-2 text-sm font-medium text-[#1E1E1E] hover:bg-[#FAF6EF]"
            title="Scan orders for column integrity and data that can crash year analytics">
            Audit columns…
        </button>
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

    @if ($descModalOpen)
        @php
            $descPct = $descTotal > 0
                ? min(100, (int) round(($descScanned / $descTotal) * 100))
                : ($descDone ? 100 : 0);
        @endphp
        <div class="fixed inset-0 z-[74] flex items-end justify-center bg-black/50 p-4 sm:items-center"
            wire:click.self="closeLegacyDescriptionModal"
            role="dialog"
            aria-modal="true"
            aria-label="Copy legacy product descriptions">
            @if ($descRunning)
                <div wire:poll.400ms="continueLegacyDescriptionImport" class="hidden" aria-hidden="true"></div>
            @endif
            <div class="w-full max-w-lg overflow-hidden rounded-xl border border-[#EFE7D6] bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-[#EFE7D6] px-4 py-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-[#1E1E1E]">Copy legacy descriptions</h2>
                        <p class="mt-0.5 text-xs text-[#8C8474]">
                            Batches of {{ \App\Services\LegacyImport\LegacyDescriptionImporter::BATCH_SIZE }}.
                            Copies legacy <code class="text-[11px]">product_detail</code> /
                            <code class="text-[11px]">product_detail_bn</code> into sun2
                            <code class="text-[11px]">description</code> /
                            <code class="text-[11px]">description_bn</code> (matched by product id).
                            Scripts and unsafe markup are stripped.
                        </p>
                    </div>
                    <button type="button"
                        wire:click="closeLegacyDescriptionModal"
                        class="rounded-lg border border-[#E0D6C2] px-2.5 py-1 text-xs text-[#6B6459] hover:border-[#C9A227]"
                        aria-label="Close legacy description import">
                        Close
                    </button>
                </div>

                <div class="space-y-4 px-4 py-4">
                    @if (! $descRunning && ! $descDone)
                        <label class="flex items-start gap-2 text-sm text-[#6B6459]">
                            <input type="checkbox"
                                wire:model.live="descForce"
                                class="mt-0.5 rounded border-[#E0D6C2] text-[#1F4E79] focus:ring-[#1F4E79]"
                                data-desc-force>
                            <span>
                                <span class="font-medium text-[#1E1E1E]">Overwrite existing</span>
                                <span class="block text-xs text-[#8C8474]">
                                    Replace non-empty sun2 descriptions. Off = fill empty fields only.
                                </span>
                            </span>
                        </label>
                    @elseif ($descForce)
                        <p class="text-xs text-[#8C8474]">Overwrite existing: on</p>
                    @endif

                    <div>
                        <div class="mb-1.5 flex items-center justify-between text-xs text-[#6B6459]">
                            <span data-desc-progress-label>{{ $descStatusLine }}</span>
                            <span class="tabular-nums font-medium text-[#1E1E1E]">{{ $descPct }}%</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-[#EFE7D6]" role="progressbar"
                            aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $descPct }}">
                            <div class="h-full rounded-full bg-[#1F4E79] transition-[width] duration-300"
                                style="width: {{ $descPct }}%"></div>
                        </div>
                    </div>

                    <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] text-[#8C8474]">Scanned</dt>
                            <dd class="mt-0.5 tabular-nums font-semibold text-[#1E1E1E]" data-desc-scanned>
                                {{ number_format($descScanned) }}
                                <span class="font-normal text-[#8C8474]">/ {{ number_format($descTotal) }}</span>
                            </dd>
                        </div>
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] text-[#8C8474]">Updated</dt>
                            <dd class="mt-0.5 tabular-nums font-semibold text-[#1E1E1E]" data-desc-updated>
                                {{ number_format($descUpdated) }}
                            </dd>
                        </div>
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] text-[#8C8474]">Skipped</dt>
                            <dd class="mt-0.5 tabular-nums font-semibold text-[#1E1E1E]" data-desc-skipped>
                                {{ number_format($descSkipped) }}
                            </dd>
                        </div>
                    </dl>

                    @if ($descRecentFixes !== [])
                        <div>
                            <p class="text-[11px] font-medium text-[#6B6459]">Recent updates</p>
                            <p class="mt-1 text-xs text-[#8C8474]" data-desc-recent>
                                {{ implode(' · ', $descRecentFixes) }}
                            </p>
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-2 border-t border-[#EFE7D6] pt-3">
                        @if ($descRunning)
                            <button type="button"
                                wire:click="stopLegacyDescriptionImport"
                                class="rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-sm font-medium text-[#6B6459] hover:border-[#C9A227]">
                                Pause
                            </button>
                        @elseif ($descDone)
                            <button type="button"
                                wire:click="openLegacyDescriptionModal"
                                class="rounded-lg border border-[#1F4E79] px-3 py-1.5 text-sm font-medium text-[#1F4E79] hover:bg-[#FAF6EF]">
                                Run again
                            </button>
                            <button type="button"
                                wire:click="closeLegacyDescriptionModal"
                                class="rounded-lg bg-[#1F4E79] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#183d5e]">
                                Close
                            </button>
                        @elseif ($descScanned > 0)
                            <button type="button"
                                wire:click="startLegacyDescriptionImport"
                                class="rounded-lg bg-[#1F4E79] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#183d5e]"
                                data-desc-start>
                                Resume / Next
                            </button>
                        @else
                            <button type="button"
                                wire:click="startLegacyDescriptionImport"
                                class="rounded-lg bg-[#1F4E79] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#183d5e]"
                                data-desc-start>
                                Start
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($settlementModalOpen)
        @php
            $settlementPct = $settlementTotal > 0
                ? min(100, (int) round(($settlementScanned / $settlementTotal) * 100))
                : ($settlementDone ? 100 : 0);
        @endphp
        <div class="fixed inset-0 z-[75] flex items-end justify-center bg-black/50 p-4 sm:items-center"
            wire:click.self="closeSettlementRepairModal"
            role="dialog"
            aria-modal="true"
            aria-label="Repair settlement and courier charges">
            @if ($settlementRunning)
                <div wire:poll.400ms="continueSettlementRepair" class="hidden" aria-hidden="true"></div>
            @endif
            <div class="w-full max-w-lg overflow-hidden rounded-xl border border-[#EFE7D6] bg-white shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-[#EFE7D6] px-4 py-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-[#1E1E1E]">Repair settlement &amp; courier</h2>
                        <p class="mt-0.5 text-xs text-[#8C8474]">
                            Batches of {{ \App\Services\Admin\OrderSettlementCourierRepairService::BATCH_SIZE }}.
                            Fills ৳0 courier on delivered/returned from the piece-based rate card
                            (uses the order’s courier, or the default courier when unset; min 1 piece),
                            then settles delivered non-exchange bills to payment ledger (mark paid).
                        </p>
                    </div>
                    <button type="button"
                        wire:click="closeSettlementRepairModal"
                        class="rounded-lg border border-[#E0D6C2] px-2.5 py-1 text-xs text-[#6B6459] hover:border-[#C9A227]"
                        aria-label="Close settlement repair">
                        Close
                    </button>
                </div>

                <div class="space-y-4 px-4 py-4">
                    <div>
                        <div class="mb-1.5 flex items-center justify-between text-xs text-[#6B6459]">
                            <span data-settlement-progress-label>{{ $settlementStatusLine }}</span>
                            <span class="tabular-nums font-medium text-[#1E1E1E]">{{ $settlementPct }}%</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-[#EFE7D6]" role="progressbar"
                            aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $settlementPct }}">
                            <div class="h-full rounded-full bg-[#C9A227] transition-[width] duration-300"
                                style="width: {{ $settlementPct }}%"></div>
                        </div>
                    </div>

                    <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] text-[#8C8474]">Scanned</dt>
                            <dd class="mt-0.5 tabular-nums font-semibold text-[#1E1E1E]" data-settlement-scanned>
                                {{ number_format($settlementScanned) }}
                                <span class="font-normal text-[#8C8474]">/ {{ number_format($settlementTotal) }}</span>
                            </dd>
                        </div>
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] text-[#8C8474]">Orders fixed</dt>
                            <dd class="mt-0.5 tabular-nums font-semibold text-[#1E1E1E]" data-settlement-fixed>
                                {{ number_format($settlementFixedOrders) }}
                            </dd>
                        </div>
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] text-[#8C8474]">Courier filled</dt>
                            <dd class="mt-0.5 tabular-nums font-semibold text-[#1E1E1E]" data-settlement-courier>
                                {{ number_format($settlementCourierFixed) }}
                            </dd>
                        </div>
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] text-[#8C8474]">Settlements</dt>
                            <dd class="mt-0.5 tabular-nums font-semibold text-[#1E1E1E]" data-settlement-settled>
                                {{ number_format($settlementSettlementFixed) }}
                            </dd>
                        </div>
                    </dl>

                    @if ($settlementRecentFixes !== [])
                        <div>
                            <p class="text-[11px] font-medium text-[#6B6459]">Recent fixes</p>
                            <p class="mt-1 text-xs tabular-nums text-[#8C8474]" data-settlement-recent>
                                {{ implode(' · ', $settlementRecentFixes) }}
                            </p>
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-2 border-t border-[#EFE7D6] pt-3">
                        @if (! $settlementRunning && ! $settlementDone)
                            <button type="button"
                                wire:click="startSettlementRepair"
                                class="rounded-lg bg-[#C9A227] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#b89220]">
                                Start
                            </button>
                        @endif
                        @if ($settlementRunning)
                            <button type="button"
                                wire:click="stopSettlementRepair"
                                class="rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-sm font-medium text-[#6B6459] hover:border-[#C9A227]">
                                Pause
                            </button>
                        @endif
                        @if (! $settlementRunning && $settlementScanned > 0 && ! $settlementDone)
                            <button type="button"
                                wire:click="startSettlementRepair"
                                class="rounded-lg bg-[#C9A227] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#b89220]">
                                Resume
                            </button>
                        @endif
                        @if ($settlementDone)
                            <button type="button"
                                wire:click="openSettlementRepairModal"
                                class="rounded-lg border border-[#C9A227] px-3 py-1.5 text-sm font-medium text-[#C9A227] hover:bg-[#FAF6EF]">
                                Run again
                            </button>
                            <button type="button"
                                wire:click="closeSettlementRepairModal"
                                class="rounded-lg bg-[#C9A227] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#b89220]">
                                Close
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($auditModalOpen)
        @php
            $auditPct = $auditTotal > 0
                ? min(100, (int) round(($auditScanned / $auditTotal) * 100))
                : ($auditDone ? 100 : 0);
        @endphp
        <div class="fixed inset-0 z-[76] flex items-end justify-center bg-black/50 p-4 sm:items-center"
            wire:click.self="closeCalculationAuditModal"
            role="dialog"
            aria-modal="true"
            aria-label="Audit order columns">
            @if ($auditRunning)
                <div wire:poll.400ms="continueCalculationAudit" class="hidden" aria-hidden="true"></div>
            @endif
            <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-[#EFE7D6] bg-white shadow-xl">
                <div class="flex shrink-0 items-start justify-between gap-3 border-b border-[#EFE7D6] px-4 py-3">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-[#1E1E1E]">Audit order columns</h2>
                        <p class="mt-0.5 text-xs text-[#8C8474]">
                            Report-only: checks Revenue / COGS / packaging / courier / COD / Direct / P/L integrity,
                            missing costs, and bad dates that can 500 year analytics pages.
                            Batches of {{ \App\Services\Admin\OrderCalculationAuditService::BATCH_SIZE }}.
                        </p>
                    </div>
                    <button type="button"
                        wire:click="closeCalculationAuditModal"
                        class="rounded-lg border border-[#E0D6C2] px-2.5 py-1 text-xs text-[#6B6459] hover:border-[#C9A227]"
                        aria-label="Close column audit">
                        Close
                    </button>
                </div>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4">
                    @if (! $auditRunning && ! $auditDone)
                        <label class="block text-sm text-[#6B6459]">
                            <span class="text-[11px] font-medium uppercase tracking-wide text-[#8C8474]">Scope</span>
                            <select wire:model.live="auditYear"
                                data-audit-year
                                class="mt-1 w-full rounded-lg border border-[#E0D6C2] bg-white px-3 py-2 text-sm">
                                <option value="">All years</option>
                                @foreach ($auditYearOptions as $yearOption)
                                    <option value="{{ $yearOption }}">{{ $yearOption }}</option>
                                @endforeach
                            </select>
                        </label>
                    @elseif ($auditYear !== '')
                        <p class="text-xs text-[#8C8474]" data-audit-year-locked>Scoped to year {{ $auditYear }}</p>
                    @endif

                    <div>
                        <div class="mb-1.5 flex items-center justify-between text-xs text-[#6B6459]">
                            <span data-audit-progress-label>{{ $auditStatusLine }}</span>
                            <span class="tabular-nums font-medium text-[#1E1E1E]">{{ $auditPct }}%</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-[#EFE7D6]" role="progressbar"
                            aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $auditPct }}">
                            <div class="h-full rounded-full bg-[#1E1E1E] transition-[width] duration-300"
                                style="width: {{ $auditPct }}%"></div>
                        </div>
                    </div>

                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] text-[#8C8474]">Scanned</dt>
                            <dd class="mt-0.5 tabular-nums font-semibold text-[#1E1E1E]" data-audit-scanned>
                                {{ number_format($auditScanned) }}
                                <span class="font-normal text-[#8C8474]">/ {{ number_format($auditTotal) }}</span>
                            </dd>
                        </div>
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] text-[#8C8474]">Orders with issues</dt>
                            <dd class="mt-0.5 tabular-nums font-semibold text-[#1E1E1E]" data-audit-manual>
                                {{ number_format($auditManualNeeded) }}
                            </dd>
                        </div>
                    </dl>

                    @if ($auditIssues !== [])
                        <div data-audit-issues>
                            <p class="text-[11px] font-medium text-[#6B6459]">
                                Issues found
                                @if ($auditIssuesTruncated)
                                    <span class="font-normal">(list capped)</span>
                                @endif
                            </p>
                            <ul class="mt-2 max-h-64 space-y-2 overflow-y-auto text-sm">
                                @foreach ($auditIssues as $row)
                                    <li wire:key="audit-issue-{{ $row['order_id'] }}"
                                        class="rounded-lg border border-[#EFE7D6] px-3 py-2">
                                        <a href="{{ $row['url'] }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="font-medium text-[#C9A227] hover:underline"
                                            data-audit-order-link>
                                            {{ $row['order_number'] }}
                                        </a>
                                        <ul class="mt-1 list-disc space-y-0.5 pl-4 text-xs text-[#6B6459]">
                                            @foreach ($row['issues'] as $issue)
                                                <li>{{ $issue }}</li>
                                            @endforeach
                                        </ul>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @elseif ($auditDone && $auditManualNeeded === 0)
                        <p class="text-sm text-emerald-800" data-audit-all-clear>
                            All scanned orders have consistent contribution columns and readable dates.
                        </p>
                    @endif

                    <div class="flex flex-wrap gap-2 border-t border-[#EFE7D6] pt-3">
                        @if (! $auditRunning && ! $auditDone)
                            <button type="button"
                                wire:click="startCalculationAudit"
                                class="rounded-lg bg-[#1E1E1E] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#333]">
                                Start
                            </button>
                        @endif
                        @if ($auditRunning)
                            <button type="button"
                                wire:click="stopCalculationAudit"
                                class="rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-sm font-medium text-[#6B6459] hover:border-[#C9A227]">
                                Pause
                            </button>
                        @endif
                        @if (! $auditRunning && $auditScanned > 0 && ! $auditDone)
                            <button type="button"
                                wire:click="startCalculationAudit"
                                class="rounded-lg bg-[#1E1E1E] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#333]">
                                Resume
                            </button>
                        @endif
                        @if ($auditDone)
                            <button type="button"
                                wire:click="openCalculationAuditModal"
                                class="rounded-lg border border-[#1E1E1E] px-3 py-1.5 text-sm font-medium text-[#1E1E1E] hover:bg-[#FAF6EF]">
                                Run again
                            </button>
                            <button type="button"
                                wire:click="closeCalculationAuditModal"
                                class="rounded-lg bg-[#1E1E1E] px-3 py-1.5 text-sm font-medium text-white hover:bg-[#333]">
                                Close
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($cogsModalOpen)
        <div class="fixed inset-0 z-[70] flex items-end justify-center overflow-y-auto overscroll-contain bg-black/50 p-4 sm:items-center"
            wire:click.self="closeCogsModal"
            role="dialog"
            aria-modal="true"
            aria-label="Fix product costs for order {{ $cogsModalOrderNumber }}">
            <div class="flex max-h-[min(90vh,100%)] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-[#EFE7D6] bg-white shadow-xl"
                data-cogs-modal-panel>
                <div class="flex shrink-0 items-start justify-between gap-3 border-b border-[#EFE7D6] px-4 py-3">
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
                    <div class="shrink-0 border-b border-emerald-100 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                        {{ $cogsModalMessage }}
                    </div>
                @endif

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain px-4 py-4"
                    data-cogs-modal-body>
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
                                                wire:model.live="cogsModalRows.{{ $index }}.purchase_price"
                                                @disabled($row['has_materials'])
                                                class="w-full rounded-lg border border-[#E0D6C2] px-3 py-1.5 text-sm tabular-nums disabled:bg-[#FAF6EF] disabled:text-[#8C8474]">
                                            @error('cogsModalRows.'.$index.'.purchase_price')
                                                <p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-[11px] font-medium text-[#6B6459]">Other cost</label>
                                            <input type="number" min="0" step="1"
                                                wire:model.live="cogsModalRows.{{ $index }}.other_cost"
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
                                                class="rounded-lg border border-[#C9A227] px-3 py-1.5 text-xs font-medium text-[#C9A227] hover:bg-[#FAF6EF]">
                                                Sync to open orders with this product → next
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
