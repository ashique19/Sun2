<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Couriers</h1>
            @php $totalBalance = $couriers->sum(fn ($c) => (float) $c->balance); @endphp
            <p class="text-sm text-[#6B6459] mt-1">
                Book balance total:
                <span class="tabular-nums font-medium {{ $totalBalance > 0 ? 'text-amber-700' : ($totalBalance < 0 ? 'text-emerald-700' : '') }}">
                    &#2547; {{ number_format($totalBalance, 0) }}
                </span>
                <span class="text-xs text-[#8C8474]">(positive = couriers owe you)</span>
            </p>
        </div>
        <a href="{{ route('admin.couriers.create') }}" wire:navigate
            class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
            Create Courier
        </a>
    </div>

    @if ($error)
        <div class="rounded-lg bg-rose-50 text-rose-700 text-sm px-4 py-3 mb-4">{{ $error }}</div>
    @endif
    @if ($message)
        <div class="rounded-lg bg-emerald-50 text-emerald-700 text-sm px-4 py-3 mb-4">{{ $message }}</div>
    @endif

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Slug</th>
                        <th class="px-4 py-3 font-medium">Charge</th>
                        <th class="px-4 py-3 font-medium">Book balance</th>
                        <th class="px-4 py-3 font-medium">API balance</th>
                        <th class="px-4 py-3 font-medium">Orders</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($couriers as $courier)
                        @php $apiBalance = $apiBalances[$courier->id] ?? null; @endphp
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
                            <td class="px-4 py-3 tabular-nums {{ (float) $courier->balance > 0 ? 'text-amber-700 font-medium' : ((float) $courier->balance < 0 ? 'text-emerald-700' : 'text-[#6B6459]') }}">
                                &#2547; {{ number_format($courier->balance, 0) }}
                            </td>
                            <td class="px-4 py-3 tabular-nums text-[#6B6459]">
                                @if ($apiBalance !== null)
                                    &#2547; {{ number_format($apiBalance, 0) }}
                                @elseif ($courier->slug && in_array($courier->slug, $apiSlugs, true))
                                    <span class="text-[#8C8474]">—</span>
                                @else
                                    <span class="text-[#8C8474]">n/a</span>
                                @endif
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
                            <td colspan="8" class="px-4 py-8 text-center text-[#8C8474]">No couriers yet.</td>
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
</div>
