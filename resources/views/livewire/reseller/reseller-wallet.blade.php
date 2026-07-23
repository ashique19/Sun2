<div>
    <h1 class="mb-2 font-serif text-2xl font-semibold sm:text-3xl">Account balance</h1>
    <p class="mb-6 text-sm text-[#8C8474]">Commission is added after an order is delivered. Payouts appear here when paid.</p>

    <div class="mb-6 rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
        <p class="text-xs uppercase tracking-wide text-[#8C8474]">Available balance</p>
        <p class="mt-1 text-3xl font-semibold tabular-nums">&#2547; {{ number_format($balance, 0) }}</p>
        <p class="mt-2 text-xs text-[#8C8474]">
            @if ($balance > 0)
                Balance available — payouts are processed by admin
            @else
                No balance due
            @endif
        </p>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white p-4 sm:p-6">
        <h2 class="mb-4 font-semibold">Wallet ledger</h2>
        <div class="space-y-3 text-sm">
            @forelse ($entries as $entry)
                <div class="flex items-start justify-between gap-3 border-b border-[#EFE7D6] pb-3 last:border-0">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-medium capitalize">{{ str_replace('_', ' ', $entry->type) }}</p>
                            @if ($entry->type === 'payout')
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">Paid</span>
                            @endif
                        </div>
                        <p class="text-xs text-[#8C8474]">{{ $entry->created_at?->format('d M Y, h:i A') }}</p>
                        @if ($entry->note)
                            <p class="mt-1 text-[#6B6459] break-words">{{ $entry->note }}</p>
                        @endif
                    </div>
                    <div class="shrink-0 text-right">
                        <p @class(['font-semibold tabular-nums', 'text-emerald-700' => $entry->amount >= 0, 'text-rose-700' => $entry->amount < 0])>
                            {{ $entry->amount >= 0 ? '+' : '−' }} &#2547; {{ number_format(abs((float) $entry->amount), 0) }}
                        </p>
                        <p class="text-xs text-[#8C8474]">Bal &#2547; {{ number_format($entry->balance_after, 0) }}</p>
                    </div>
                </div>
            @empty
                <p class="text-[#8C8474]">No wallet activity yet.</p>
            @endforelse
        </div>
        @if ($entries->hasPages())
            <div class="mt-4">{{ $entries->links() }}</div>
        @endif
    </div>
</div>
