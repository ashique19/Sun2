<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Couriers</h1>
            <p class="text-sm text-[#6B6459] mt-1">
                Receivable:
                <span class="tabular-nums font-medium text-amber-700">&#2547; {{ number_format($totalReceivable ?? 0, 0) }}</span>
                <span class="text-[#8C8474]">·</span>
                Pending delivery:
                <span class="tabular-nums font-medium text-[#6B6459]">&#2547; {{ number_format($totalPending ?? 0, 0) }}</span>
            </p>
            <p class="text-xs text-[#8C8474] mt-1">
                Receivable = cash received − courier charge − COD % − withdrawals
                (cancelled with collected 0 still subtracts courier charge; COD % is 0).
                Pending = COD still with courier on dispatched parcels.
                Expected API = book balance (should match live Steadfast wallet after Refresh).
                API = live Steadfast wallet (refresh manually).
            </p>
            @if ($apiBalanceError)
                <p class="text-xs text-amber-700 mt-1">{{ $apiBalanceError }}</p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button"
                wire:click="loadApiBalances"
                wire:loading.attr="disabled"
                wire:target="loadApiBalances"
                class="rounded-full border border-[#E0D6C2] px-4 py-2 text-sm font-medium text-[#6B6459] hover:border-[#C9A227] hover:bg-[#FAF6EF] disabled:opacity-60">
                <span wire:loading.remove wire:target="loadApiBalances">Refresh API</span>
                <span wire:loading wire:target="loadApiBalances">Loading…</span>
            </button>
            <a href="{{ route('admin.couriers.create') }}" wire:navigate
                class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
                Create Courier
            </a>
        </div>
    </div>

    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif
    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[64rem]">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Slug</th>
                        <th class="px-4 py-3 font-medium">Charge</th>
                        <th class="px-4 py-3 font-medium">
                            <span title="Cash received minus courier fees, COD %, and withdrawals">Receivable</span>
                        </th>
                        <th class="px-4 py-3 font-medium">
                            <span title="COD still with courier on dispatched orders">Pending</span>
                        </th>
                        <th class="px-4 py-3 font-medium">
                            <span title="Live wallet balance from courier API">API balance</span>
                        </th>
                        <th class="px-4 py-3 font-medium">
                            <span title="Legacy book ledger (dispatch credits − returns − withdrawals)">Book</span>
                        </th>
                        <th class="px-4 py-3 font-medium">Orders</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($couriers as $courier)
                        @php
                            $apiBalance = $apiBalances[$courier->id] ?? null;
                            $summary = $balanceSummaries[$courier->id] ?? [
                                'pending' => 0,
                                'receivable' => 0,
                                'book' => (float) $courier->balance,
                                'expected_api' => 0,
                            ];
                            $expectedApi = (float) ($summary['expected_api'] ?? $summary['receivable'] ?? 0);
                            $apiDiff = $apiBalance !== null ? round((float) $apiBalance - $expectedApi, 2) : null;
                        @endphp
                        <tr class="hover:bg-[#FAF6EF]/60" wire:key="courier-{{ $courier->id }}">
                            <td class="px-4 py-3 font-medium">
                                {{ $courier->name }}
                                @if ($courier->is_default)
                                    <span class="ml-1 text-[10px] uppercase tracking-wide text-[#C9A227] font-semibold">Default</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-[#8C8474]">
                                {{ $courier->slug ?: '—' }}
                                @if ($courier->slug && in_array($courier->slug, $apiSlugs, true))
                                    <span class="text-[10px] text-emerald-700">API</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">&#2547; {{ number_format($courier->charge, 0) }}</td>
                            <td class="px-4 py-3 tabular-nums {{ ($summary['receivable'] ?? 0) > 0 ? 'text-amber-700 font-medium' : 'text-[#6B6459]' }}">
                                &#2547; {{ number_format($summary['receivable'] ?? 0, 0) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums text-[#6B6459]">
                                &#2547; {{ number_format($summary['pending'] ?? 0, 0) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums text-[#6B6459]">
                                @if ($apiBalancesLoading)
                                    <span class="text-[#8C8474]">…</span>
                                @elseif ($apiBalance !== null)
                                    <div>&#2547; {{ number_format($apiBalance, 0) }}</div>
                                    <div class="text-[11px] text-[#8C8474] mt-0.5" title="Book balance — should match live Steadfast wallet">
                                        Should be &#2547; {{ number_format($expectedApi, 0) }}
                                    </div>
                                    @if (abs($apiDiff) < 0.5)
                                        <div class="text-[11px] mt-0.5 text-emerald-700"
                                            title="API − book balance">
                                            Diff {{ $apiDiff > 0 ? '+' : ($apiDiff < 0 ? '−' : '') }}&#2547; {{ number_format(abs($apiDiff), 0) }}
                                        </div>
                                    @else
                                        <button type="button"
                                            wire:click="openDiffOrders({{ $courier->id }})"
                                            title="API − book balance. Open orders that explain this Diff."
                                            class="mt-0.5 text-[11px] font-semibold text-amber-700 underline decoration-amber-300 underline-offset-2 hover:text-amber-900">
                                            Diff {{ $apiDiff > 0 ? '+' : '−' }}&#2547; {{ number_format(abs($apiDiff), 0) }}
                                        </button>
                                    @endif
                                @elseif (! $apiBalancesLoaded && $courier->slug && in_array(strtolower((string) $courier->slug), $apiSlugs, true))
                                    <div class="text-[#8C8474]">Tap Refresh API</div>
                                    <div class="text-[11px] text-[#8C8474] mt-0.5" title="Book balance — should match live Steadfast wallet">
                                        Should be &#2547; {{ number_format($expectedApi, 0) }}
                                    </div>
                                @elseif ($courier->slug && in_array(strtolower((string) $courier->slug), $apiSlugs, true))
                                    <div class="text-[#8C8474]" title="{{ $apiBalanceError ?: 'Unavailable' }}">—</div>
                                    <div class="text-[11px] text-[#8C8474] mt-0.5" title="Book balance — should match live Steadfast wallet">
                                        Should be &#2547; {{ number_format($expectedApi, 0) }}
                                    </div>
                                @else
                                    <span class="text-[#8C8474]">n/a</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 tabular-nums text-[#8C8474]">
                                &#2547; {{ number_format($summary['book'] ?? $courier->balance, 0) }}
                            </td>
                            <td class="px-4 py-3">{{ $courier->orders_count }}</td>
                            <td class="px-4 py-3">
                                @if ($courier->is_active)
                                    <span class="text-emerald-700">Active</span>
                                @else
                                    <span class="text-[#8C8474]">Inactive</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                @if ((float) $courier->balance > 0)
                                    <button type="button"
                                        wire:click="openWithdraw({{ $courier->id }})"
                                        class="text-[#6B6459] hover:text-[#C9A227] hover:underline">Withdraw</button>
                                @endif
                                <a href="{{ route('admin.couriers.edit', $courier) }}" wire:navigate
                                    class="text-[#C9A227] hover:underline">Edit</a>
                                @if (! $courier->is_default && $courier->orders_count === 0)
                                    <button type="button"
                                        wire:click="delete({{ $courier->id }})"
                                        wire:confirm="Delete “{{ $courier->name }}”?"
                                        class="text-rose-600 hover:underline">Delete</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-8 text-center text-[#8C8474]">No couriers yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($showWithdrawModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" wire:click.self="closeWithdraw">
            <div class="w-full max-w-md rounded-xl border border-[#EFE7D6] bg-white p-6 shadow-xl space-y-4"
                wire:key="withdraw-modal-{{ $withdrawCourierId }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold text-lg">Withdraw from {{ $withdrawCourierName }}</h2>
                        <p class="text-xs text-[#8C8474] mt-1">
                            Book balance: &#2547; {{ number_format((float) $withdrawBookBalance, 0) }}.
                            Enter the amount you received from the courier.
                        </p>
                    </div>
                    <button type="button" wire:click="closeWithdraw" class="text-sm text-[#8C8474] hover:text-[#1E1E1E]">Close</button>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Amount (&#2547;)</label>
                    <input type="number" min="1" max="{{ max(0, (int) $withdrawBookBalance) }}" step="1" wire:model="withdrawAmount"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    @error('withdrawAmount') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Note (optional)</label>
                    <input type="text" wire:model="withdrawNote" placeholder="e.g. Bank transfer 10 Jul"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                    @error('withdrawNote') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-wrap gap-3 pt-1">
                    <button type="button" wire:click="confirmWithdraw"
                        class="rounded-full bg-[#C9A227] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                        Record withdrawal
                    </button>
                    <button type="button" wire:click="closeWithdraw"
                        class="rounded-full border border-[#E0D6C2] px-6 py-2.5 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($showDiffModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" wire:click.self="closeDiffOrders">
            <div class="w-full max-w-3xl max-h-[85vh] overflow-hidden rounded-xl border border-[#EFE7D6] bg-white shadow-xl flex flex-col"
                wire:key="diff-modal-{{ $diffCourierId }}">
                <div class="flex items-start justify-between gap-3 border-b border-[#EFE7D6] px-5 py-4">
                    <div class="min-w-0">
                        <h2 class="font-semibold text-lg">Balance Diff — {{ $diffCourierName }}</h2>
                        <p class="text-xs text-[#8C8474] mt-1">
                            API &#2547; {{ number_format((float) $diffApiBalance, 0) }}
                            · Should be &#2547; {{ number_format((float) $diffExpectedApi, 0) }}
                            · Diff
                            <span class="font-semibold text-amber-700">
                                {{ (float) $diffAmount > 0 ? '+' : ((float) $diffAmount < 0 ? '−' : '') }}&#2547;
                                {{ number_format(abs((float) $diffAmount), 0) }}
                            </span>
                        </p>
                        <p class="text-xs text-[#8C8474] mt-1">
                            Orders where Steadfast webhook collected amount (or return book credit) does not match what we expect.
                        </p>
                    </div>
                    <button type="button" wire:click="closeDiffOrders" class="text-sm text-[#8C8474] hover:text-[#1E1E1E]">Close</button>
                </div>

                <div class="overflow-y-auto px-5 py-3">
                    @if ($diffOrders === [])
                        <p class="py-8 text-center text-sm text-[#8C8474]">
                            No per-order COD / webhook mismatches found. Diff may come from courier fees, withdrawals timing, or older ledger gaps.
                        </p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm min-w-[40rem]">
                                <thead class="text-left text-[#6B6459]">
                                    <tr class="border-b border-[#EFE7D6]">
                                        <th class="py-2 pr-3 font-medium">Order</th>
                                        <th class="py-2 pr-3 font-medium">Reason</th>
                                        <th class="py-2 pr-3 font-medium text-right">Expected</th>
                                        <th class="py-2 pr-3 font-medium text-right">Courier collected</th>
                                        <th class="py-2 font-medium text-right">Delta</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#E7DFCF]">
                                    @foreach ($diffOrders as $row)
                                        <tr wire:key="diff-order-{{ $row['order_id'] }}-{{ $row['reason'] }}">
                                            <td class="py-2.5 pr-3 align-top">
                                                <a href="{{ route('admin.orders.show', $row['order_id']) }}" wire:navigate
                                                    class="font-medium text-[#C9A227] hover:underline">
                                                    #{{ $row['order_number'] }}
                                                </a>
                                                <div class="text-xs text-[#8C8474]">{{ $row['customer'] }}</div>
                                                <div class="text-[11px] capitalize text-[#8C8474]">{{ $row['status'] }}</div>
                                            </td>
                                            <td class="py-2.5 pr-3 align-top">
                                                <div class="text-xs font-medium text-[#1E1E1E]">{{ $row['reason_label'] }}</div>
                                                @if (! empty($row['tracking_message']))
                                                    <div class="mt-0.5 text-[11px] text-[#8C8474] line-clamp-2">{{ $row['tracking_message'] }}</div>
                                                @endif
                                            </td>
                                            <td class="py-2.5 pr-3 align-top text-right tabular-nums">
                                                &#2547; {{ number_format((float) $row['book_expected'], 0) }}
                                            </td>
                                            <td class="py-2.5 pr-3 align-top text-right tabular-nums">
                                                @if ($row['courier_collected'] === null)
                                                    —
                                                @else
                                                    &#2547; {{ number_format((float) $row['courier_collected'], 0) }}
                                                @endif
                                            </td>
                                            <td class="py-2.5 align-top text-right tabular-nums font-medium {{ abs((float) $row['delta']) > 1 ? 'text-amber-700' : 'text-[#6B6459]' }}">
                                                {{ (float) $row['delta'] > 0 ? '+' : ((float) $row['delta'] < 0 ? '−' : '') }}&#2547;
                                                {{ number_format(abs((float) $row['delta']), 0) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="border-t border-[#EFE7D6] px-5 py-3 flex justify-end">
                    <button type="button" wire:click="closeDiffOrders"
                        class="rounded-full border border-[#E0D6C2] px-5 py-2 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
