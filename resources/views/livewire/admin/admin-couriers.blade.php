<div data-couriers-page>
    <div class="mb-4 flex flex-col gap-3 sm:mb-6 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="font-serif text-2xl font-semibold sm:text-3xl">Couriers</h1>
        </div>
        <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
            <button type="button"
                wire:click="loadApiBalances"
                wire:loading.attr="disabled"
                wire:target="loadApiBalances"
                class="inline-flex min-h-11 items-center justify-center rounded-full border border-[#E0D6C2] px-4 py-2 text-sm font-medium text-[#6B6459] hover:border-[#C9A227] hover:bg-[#FAF6EF] disabled:opacity-60 sm:min-h-0">
                <span wire:loading.remove wire:target="loadApiBalances">Refresh API</span>
                <span wire:loading wire:target="loadApiBalances">Loading…</span>
            </button>
            <a href="{{ route('admin.couriers.create') }}" wire:navigate
                class="inline-flex min-h-11 items-center justify-center rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] sm:min-h-0">
                Create Courier
            </a>
        </div>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3" data-couriers-totals>
        <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-3">
            <p class="text-[11px] font-medium uppercase tracking-wide text-[#8C8474]">Receivable</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-amber-700">&#2547; {{ number_format($totalReceivable ?? 0, 0) }}</p>
        </div>
        <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-3">
            <p class="text-[11px] font-medium uppercase tracking-wide text-[#8C8474]">Pending delivery</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-[#6B6459]">&#2547; {{ number_format($totalPending ?? 0, 0) }}</p>
        </div>
    </div>

    <details class="mb-4 rounded-xl border border-[#EFE7D6] bg-white px-4 py-3 text-xs text-[#8C8474]" data-couriers-help>
        <summary class="cursor-pointer text-sm font-medium text-[#6B6459]">How receivable, pending, and API relate</summary>
        <p class="mt-2 leading-relaxed">
            Receivable = net owed from delivered/settled parcels − courier charge − COD charge − withdrawals
            (cancelled with collected 0 still subtracts courier charge; COD % is 0).
            Pending = COD still with courier on undelivered (dispatched) parcels.
            Expected API = receivable (net owed after courier charge, COD charge, and withdrawals).
            API = live Steadfast wallet (refresh manually).
            If receivable looks lifelong-huge vs API, use Neutralize to record prior remittances (book stays unchanged).
        </p>
    </details>

    @if ($apiBalanceError)
        <p class="mb-4 text-xs text-amber-700">{{ $apiBalanceError }}</p>
    @endif

    @if ($error)
        <div class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $error }}</div>
    @endif
    @if ($message)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ $message }}</div>
    @endif

    @if ($couriers->isEmpty())
        <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-8 text-center text-sm text-[#8C8474]">
            No couriers yet.
        </div>
    @else
        <div class="space-y-3 lg:hidden" data-couriers-cards>
            @foreach ($couriers as $courier)
                @php
                    $summary = $balanceSummaries[$courier->id] ?? [
                        'pending' => 0,
                        'receivable' => 0,
                        'book' => (float) $courier->balance,
                        'expected_api' => 0,
                    ];
                    $receivable = (float) ($summary['receivable'] ?? 0);
                    $receivableClass = $receivable > 0
                        ? 'text-amber-700 font-medium'
                        : ($receivable < 0 ? 'text-rose-700 font-medium' : 'text-[#6B6459]');
                @endphp
                <article class="rounded-xl border border-[#EFE7D6] bg-white p-4" wire:key="courier-card-{{ $courier->id }}" data-courier-card>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="truncate font-semibold">
                                {{ $courier->name }}
                                @if ($courier->is_default)
                                    <span class="ml-1 align-middle text-[10px] font-semibold uppercase tracking-wide text-[#C9A227]">Default</span>
                                @endif
                            </h2>
                            <p class="mt-0.5 text-xs text-[#8C8474]">
                                @if ($courier->slug)
                                    {{ $courier->slug }}
                                    @if (in_array($courier->slug, $apiSlugs, true))
                                        <span class="text-emerald-700">· API</span>
                                    @endif
                                    ·
                                @endif
                                Charge &#2547; {{ number_format($courier->charge, 0) }}
                                · {{ $courier->orders_count }} {{ $courier->orders_count === 1 ? 'order' : 'orders' }}
                            </p>
                        </div>
                        @if ($courier->is_active)
                            <span class="shrink-0 text-xs font-medium text-emerald-700">Active</span>
                        @else
                            <span class="shrink-0 text-xs text-[#8C8474]">Inactive</span>
                        @endif
                    </div>

                    <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] uppercase tracking-wide text-[#8C8474]">Receivable</dt>
                            <dd class="mt-0.5 tabular-nums {{ $receivableClass }}">&#2547; {{ number_format($receivable, 0) }}</dd>
                        </div>
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] uppercase tracking-wide text-[#8C8474]">Pending</dt>
                            <dd class="mt-0.5 tabular-nums text-[#6B6459]">&#2547; {{ number_format($summary['pending'] ?? 0, 0) }}</dd>
                        </div>
                        <div class="col-span-2 rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] uppercase tracking-wide text-[#8C8474]">API balance</dt>
                            <dd class="mt-0.5">
                                @include('livewire.admin.partials.courier-api-balance')
                            </dd>
                        </div>
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] uppercase tracking-wide text-[#8C8474]">Book</dt>
                            <dd class="mt-0.5 tabular-nums text-[#8C8474]">&#2547; {{ number_format($summary['book'] ?? $courier->balance, 0) }}</dd>
                        </div>
                        <div class="rounded-lg bg-[#FAF6EF] px-3 py-2">
                            <dt class="text-[11px] uppercase tracking-wide text-[#8C8474]">Orders</dt>
                            <dd class="mt-0.5 tabular-nums">{{ $courier->orders_count }}</dd>
                        </div>
                    </dl>

                    <div class="mt-3 border-t border-[#EFE7D6] pt-2">
                        @include('livewire.admin.partials.courier-actions', ['align' => 'start'])
                    </div>
                </article>
            @endforeach
        </div>

        <div class="hidden overflow-hidden rounded-xl border border-[#EFE7D6] bg-white lg:block" data-couriers-table>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
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
                        @foreach ($couriers as $courier)
                            @php
                                $summary = $balanceSummaries[$courier->id] ?? [
                                    'pending' => 0,
                                    'receivable' => 0,
                                    'book' => (float) $courier->balance,
                                    'expected_api' => 0,
                                ];
                                $receivable = (float) ($summary['receivable'] ?? 0);
                                $receivableClass = $receivable > 0
                                    ? 'text-amber-700 font-medium'
                                    : ($receivable < 0 ? 'text-rose-700 font-medium' : 'text-[#6B6459]');
                            @endphp
                            <tr class="hover:bg-[#FAF6EF]/60" wire:key="courier-{{ $courier->id }}">
                                <td class="px-4 py-3 font-medium">
                                    {{ $courier->name }}
                                    @if ($courier->is_default)
                                        <span class="ml-1 text-[10px] font-semibold uppercase tracking-wide text-[#C9A227]">Default</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-[#8C8474]">
                                    {{ $courier->slug ?: '—' }}
                                    @if ($courier->slug && in_array($courier->slug, $apiSlugs, true))
                                        <span class="text-[10px] text-emerald-700">API</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">&#2547; {{ number_format($courier->charge, 0) }}</td>
                                <td class="px-4 py-3 tabular-nums {{ $receivableClass }}">
                                    &#2547; {{ number_format($receivable, 0) }}
                                </td>
                                <td class="px-4 py-3 tabular-nums text-[#6B6459]">
                                    &#2547; {{ number_format($summary['pending'] ?? 0, 0) }}
                                </td>
                                <td class="px-4 py-3 tabular-nums text-[#6B6459]">
                                    @include('livewire.admin.partials.courier-api-balance')
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
                                <td class="px-4 py-3">
                                    @include('livewire.admin.partials.courier-actions', ['align' => 'end'])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($showWithdrawModal)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" wire:click.self="closeWithdraw">
            <div class="w-full max-w-md space-y-4 rounded-t-2xl border border-[#EFE7D6] bg-white px-5 pt-5 shadow-xl sm:rounded-xl sm:p-6 pb-[max(1.25rem,env(safe-area-inset-bottom))]"
                wire:key="withdraw-modal-{{ $withdrawCourierId }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Withdraw from {{ $withdrawCourierName }}</h2>
                        <p class="mt-1 text-xs text-[#8C8474]">
                            Book balance: &#2547; {{ number_format((float) $withdrawBookBalance, 0) }}.
                            Enter the amount you received from the courier.
                        </p>
                    </div>
                    <button type="button" wire:click="closeWithdraw" class="inline-flex min-h-11 items-center text-sm text-[#8C8474] hover:text-[#1E1E1E] sm:min-h-0">Close</button>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Amount (&#2547;)</label>
                    <input type="number" min="1" max="{{ max(0, (int) $withdrawBookBalance) }}" step="1" wire:model="withdrawAmount"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2.5 text-sm">
                    @error('withdrawAmount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Note (optional)</label>
                    <input type="text" wire:model="withdrawNote" placeholder="e.g. Bank transfer 10 Jul"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2.5 text-sm">
                    @error('withdrawNote') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 gap-2 pt-1 sm:flex sm:flex-wrap sm:gap-3">
                    <button type="button" wire:click="confirmWithdraw"
                        class="inline-flex min-h-11 items-center justify-center rounded-full bg-[#C9A227] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                        Record withdrawal
                    </button>
                    <button type="button" wire:click="closeWithdraw"
                        class="inline-flex min-h-11 items-center justify-center rounded-full border border-[#E0D6C2] px-6 py-2.5 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($showNeutralizeModal)
        @php
            $neutralizeCurrent = (int) round((float) $neutralizeCurrentReceivable);
            $neutralizeTargetInt = is_numeric($neutralizeTarget) ? (int) $neutralizeTarget : null;
            $neutralizeDelta = $neutralizeTargetInt !== null
                ? $neutralizeCurrent - $neutralizeTargetInt
                : null;
        @endphp
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" wire:click.self="closeNeutralize">
            <div class="max-h-[90dvh] w-full max-w-lg space-y-4 overflow-y-auto rounded-t-2xl border border-[#EFE7D6] bg-white px-5 pt-5 shadow-xl sm:rounded-xl sm:p-6 pb-[max(1.25rem,env(safe-area-inset-bottom))]"
                wire:key="neutralize-modal-{{ $neutralizeCourierId }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Neutralize receivable — {{ $neutralizeCourierName }}</h2>
                        <p class="mt-1 text-xs text-[#8C8474]">
                            Adjusts receivable to match reality (prior remittances or fee write-off).
                            <span class="font-medium text-[#6B6459]">Book balance is not changed.</span>
                        </p>
                    </div>
                    <button type="button" wire:click="closeNeutralize" class="inline-flex min-h-11 items-center text-sm text-[#8C8474] hover:text-[#1E1E1E] sm:min-h-0">Close</button>
                </div>

                <dl class="grid grid-cols-2 gap-3 rounded-lg border border-[#EFE7D6] bg-[#FAF6EF]/60 px-4 py-3 text-sm">
                    <div>
                        <dt class="text-xs text-[#8C8474]">Current receivable</dt>
                        <dd @class(['font-semibold tabular-nums', 'text-rose-700' => $neutralizeCurrent < 0])>
                            &#2547; {{ number_format($neutralizeCurrent, 0) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[#8C8474]">Book</dt>
                        <dd class="tabular-nums">&#2547; {{ number_format((float) $neutralizeBook, 0) }}</dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-xs text-[#8C8474]">Live API</dt>
                        <dd class="tabular-nums">
                            @if ($neutralizeApi !== '')
                                &#2547; {{ number_format((float) $neutralizeApi, 0) }}
                            @else
                                <span class="text-[#8C8474]">Tap Refresh API first for a suggested target</span>
                            @endif
                        </dd>
                    </div>
                </dl>

                <div class="flex flex-wrap gap-2">
                    @if ($neutralizeApi !== '')
                        <button type="button" wire:click="setNeutralizeTargetToApi"
                            class="inline-flex min-h-11 items-center rounded-full border border-[#E0D6C2] px-3 py-1 text-xs font-medium text-[#6B6459] hover:border-[#C9A227] hover:bg-[#FAF6EF] sm:min-h-0">
                            Match API
                        </button>
                    @endif
                    <button type="button" wire:click="setNeutralizeTargetToBook"
                        class="inline-flex min-h-11 items-center rounded-full border border-[#E0D6C2] px-3 py-1 text-xs font-medium text-[#6B6459] hover:border-[#C9A227] hover:bg-[#FAF6EF] sm:min-h-0">
                        Match book
                    </button>
                    <button type="button" wire:click="setNeutralizeTargetToZero"
                        class="inline-flex min-h-11 items-center rounded-full border border-[#E0D6C2] px-3 py-1 text-xs font-medium text-[#6B6459] hover:border-[#C9A227] hover:bg-[#FAF6EF] sm:min-h-0">
                        Zero
                    </button>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Target receivable after neutralize (&#2547;)</label>
                    <input type="number" min="0" step="1" wire:model.live="neutralizeTarget"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2.5 text-sm">
                    @error('neutralizeTarget') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    @if ($neutralizeDelta !== null && $neutralizeDelta !== 0)
                        <p class="mt-1 text-xs text-[#6B6459]">
                            @if ($neutralizeDelta > 0)
                                Will record prior remittance of
                                <span class="font-semibold tabular-nums">&#2547; {{ number_format($neutralizeDelta, 0) }}</span>
                            @else
                                Will credit receivable by
                                <span class="font-semibold tabular-nums">&#2547; {{ number_format(abs($neutralizeDelta), 0) }}</span>
                            @endif
                            (receivable {{ number_format($neutralizeCurrent, 0) }} → {{ number_format($neutralizeTargetInt, 0) }}).
                        </p>
                    @endif
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">Note (optional)</label>
                    <input type="text" wire:model="neutralizeNote" placeholder="e.g. Catch-up remittances / cancel-fee write-off"
                        class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2.5 text-sm">
                    @error('neutralizeNote') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 gap-2 pt-1 sm:flex sm:flex-wrap sm:gap-3">
                    <button type="button" wire:click="confirmNeutralize"
                        wire:confirm="Adjust receivable to the target? Book balance will not change."
                        class="inline-flex min-h-11 items-center justify-center rounded-full bg-[#C9A227] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                        Record adjustment
                    </button>
                    <button type="button" wire:click="closeNeutralize"
                        class="inline-flex min-h-11 items-center justify-center rounded-full border border-[#E0D6C2] px-6 py-2.5 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($showDiffModal)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" wire:click.self="closeDiffOrders">
            <div class="flex max-h-[90dvh] w-full max-w-3xl flex-col overflow-hidden rounded-t-2xl border border-[#EFE7D6] bg-white shadow-xl sm:rounded-xl"
                wire:key="diff-modal-{{ $diffCourierId }}">
                <div class="flex items-start justify-between gap-3 border-b border-[#EFE7D6] px-4 py-4 sm:px-5">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold">Balance Diff — {{ $diffCourierName }}</h2>
                        <p class="mt-1 text-xs text-[#8C8474]">
                            API &#2547; {{ number_format((float) $diffApiBalance, 0) }}
                            · Should be &#2547; {{ number_format((float) $diffExpectedApi, 0) }}
                            · Diff
                            <span class="font-semibold text-amber-700">
                                {{ (float) $diffAmount > 0 ? '+' : ((float) $diffAmount < 0 ? '−' : '') }}&#2547;
                                {{ number_format(abs((float) $diffAmount), 0) }}
                            </span>
                        </p>
                        <p class="mt-1 text-xs text-[#8C8474]">
                            Orders where Steadfast webhook collected amount (or return book credit) does not match what we expect.
                        </p>
                    </div>
                    <button type="button" wire:click="closeDiffOrders" class="inline-flex min-h-11 items-center text-sm text-[#8C8474] hover:text-[#1E1E1E] sm:min-h-0">Close</button>
                </div>

                <div class="overflow-y-auto px-4 py-3 sm:px-5">
                    @if ($diffOrders === [])
                        <p class="py-8 text-center text-sm text-[#8C8474]">
                            No per-order COD / webhook mismatches found. Diff may come from courier fees, withdrawals timing, or older ledger gaps.
                        </p>
                    @else
                        <div class="space-y-3 sm:hidden" data-diff-cards>
                            @foreach ($diffOrders as $row)
                                <article class="rounded-lg border border-[#EFE7D6] px-3 py-3" wire:key="diff-order-card-{{ $row['order_id'] }}-{{ $row['reason'] }}">
                                    <a href="{{ route('admin.orders.show', $row['order_id']) }}" wire:navigate
                                        class="font-medium text-[#C9A227] hover:underline">
                                        #{{ $row['order_number'] }}
                                    </a>
                                    <div class="text-xs text-[#8C8474]">{{ $row['customer'] }}</div>
                                    <div class="text-[11px] capitalize text-[#8C8474]">{{ $row['status'] }}</div>
                                    <div class="mt-2 text-xs font-medium text-[#1E1E1E]">{{ $row['reason_label'] }}</div>
                                    @if (! empty($row['tracking_message']))
                                        <div class="mt-0.5 text-[11px] text-[#8C8474]">{{ $row['tracking_message'] }}</div>
                                    @endif
                                    <dl class="mt-2 grid grid-cols-3 gap-2 text-xs">
                                        <div>
                                            <dt class="text-[#8C8474]">Expected</dt>
                                            <dd class="tabular-nums">&#2547; {{ number_format((float) $row['book_expected'], 0) }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-[#8C8474]">Collected</dt>
                                            <dd class="tabular-nums">
                                                @if ($row['courier_collected'] === null)
                                                    —
                                                @else
                                                    &#2547; {{ number_format((float) $row['courier_collected'], 0) }}
                                                @endif
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-[#8C8474]">Delta</dt>
                                            <dd class="tabular-nums font-medium {{ abs((float) $row['delta']) > 1 ? 'text-amber-700' : 'text-[#6B6459]' }}">
                                                {{ (float) $row['delta'] > 0 ? '+' : ((float) $row['delta'] < 0 ? '−' : '') }}&#2547;
                                                {{ number_format(abs((float) $row['delta']), 0) }}
                                            </dd>
                                        </div>
                                    </dl>
                                </article>
                            @endforeach
                        </div>

                        <div class="hidden overflow-x-auto sm:block" data-diff-table>
                            <table class="w-full min-w-[40rem] text-sm">
                                <thead class="text-left text-[#6B6459]">
                                    <tr class="border-b border-[#EFE7D6]">
                                        <th class="py-2 pr-3 font-medium">Order</th>
                                        <th class="py-2 pr-3 font-medium">Reason</th>
                                        <th class="py-2 pr-3 text-right font-medium">Expected</th>
                                        <th class="py-2 pr-3 text-right font-medium">Courier collected</th>
                                        <th class="py-2 text-right font-medium">Delta</th>
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
                                                    <div class="mt-0.5 line-clamp-2 text-[11px] text-[#8C8474]">{{ $row['tracking_message'] }}</div>
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

                <div class="flex justify-end border-t border-[#EFE7D6] px-4 py-3 sm:px-5 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                    <button type="button" wire:click="closeDiffOrders"
                        class="inline-flex min-h-11 w-full items-center justify-center rounded-full border border-[#E0D6C2] px-5 py-2 text-sm font-medium text-[#6B6459] hover:bg-[#FAF6EF] sm:w-auto sm:min-h-0">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
