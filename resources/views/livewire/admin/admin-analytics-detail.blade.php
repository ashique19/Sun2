<div>
    <div class="mb-6">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.analytics', ['year' => $year, 'month' => $month]) }}" wire:navigate
                class="rounded-full border border-[#E0D6C2] bg-white px-3 py-1.5 text-xs font-medium text-[#6B6459] hover:border-[#C9A227] hover:text-[#1E1E1E]">
                ← Back to {{ $periodLabel }}
            </a>
            <a href="{{ route('admin.analytics', ['year' => $year]) }}" wire:navigate
                class="rounded-full border border-[#E0D6C2] bg-white px-3 py-1.5 text-xs font-medium text-[#6B6459] hover:border-[#C9A227] hover:text-[#1E1E1E]">
                {{ $year }} overview
            </a>
        </div>
        <div class="mb-2 flex flex-wrap items-center gap-2 text-sm text-[#6B6459]">
            <a href="{{ route('admin.analytics', ['year' => $year]) }}" wire:navigate class="hover:text-[#C9A227]">{{ $year }}</a>
            <span class="text-[#8C8474]">→</span>
            <a href="{{ route('admin.analytics', ['year' => $year, 'month' => $month]) }}" wire:navigate class="hover:text-[#C9A227]">{{ $periodLabel }}</a>
            <span class="text-[#8C8474]">→</span>
            <span class="font-semibold text-[#1E1E1E]">{{ $summary['title'] }}</span>
        </div>
        <h1 class="font-serif text-3xl font-semibold">{{ $summary['title'] }}</h1>
        <p class="mt-1 text-sm text-[#8C8474]">{{ $summary['blurb'] }}</p>
        <p class="mt-3 text-2xl font-semibold tabular-nums @if(($summary['total'] ?? 0) < 0) text-rose-700 @endif">
            &#2547; {{ number_format((float) $summary['total'], 0) }}
        </p>
        @if ($metric === 'indirect')
            <a href="{{ route('admin.expenses', ['year' => $year, 'month' => $month]) }}" wire:navigate
                class="mt-2 inline-block text-sm font-medium text-[#C9A227] hover:underline">
                Manage expenses →
            </a>
        @endif
    </div>

    @if ($metric === 'direct')
        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border border-[#EFE7D6] bg-white px-3 py-2.5">
                <p class="text-[10px] uppercase tracking-wide text-[#8C8474]">COGS</p>
                <p class="mt-0.5 text-sm font-semibold tabular-nums">&#2547; {{ number_format((float) ($summary['cogs'] ?? 0), 0) }}</p>
            </div>
            <div class="rounded-lg border border-[#EFE7D6] bg-white px-3 py-2.5">
                <p class="text-[10px] uppercase tracking-wide text-[#8C8474]">Packaging</p>
                <p class="mt-0.5 text-sm font-semibold tabular-nums">&#2547; {{ number_format((float) ($summary['packaging'] ?? 0), 0) }}</p>
            </div>
            <div class="rounded-lg border border-[#EFE7D6] bg-white px-3 py-2.5">
                <p class="text-[10px] uppercase tracking-wide text-[#8C8474]">Courier</p>
                <p class="mt-0.5 text-sm font-semibold tabular-nums">&#2547; {{ number_format((float) ($summary['courier'] ?? 0), 0) }}</p>
            </div>
            <div class="rounded-lg border border-[#EFE7D6] bg-white px-3 py-2.5">
                <p class="text-[10px] uppercase tracking-wide text-[#8C8474]">COD fee</p>
                <p class="mt-0.5 text-sm font-semibold tabular-nums">&#2547; {{ number_format((float) ($summary['cod'] ?? 0), 0) }}</p>
            </div>
        </div>
    @endif

    @if ($metric === 'profit' && (float) ($summary['indirect'] ?? 0) > 0)
        <p class="mb-4 text-sm text-[#6B6459]">
            Indirect expenses this month:
            <span class="font-medium tabular-nums text-[#1E1E1E]">&#2547; {{ number_format((float) $summary['indirect'], 0) }}</span>
            (applied at month level, not per order).
        </p>
    @endif

    @if ($metric === 'indirect')
        <div class="overflow-hidden rounded-xl border border-[#EFE7D6] bg-white">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem] text-sm">
                    <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                        <tr>
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Title</th>
                            <th class="px-4 py-3 font-medium">Category</th>
                            <th class="px-4 py-3 font-medium">Kind</th>
                            <th class="px-4 py-3 font-medium text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E7DFCF]">
                        @forelse ($expenses as $row)
                            <tr class="hover:bg-[#FAF6EF]/50">
                                <td class="px-4 py-3 whitespace-nowrap text-[#8C8474]">{{ $row['spent_on'] ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ $row['title'] }}</div>
                                    @if (! empty($row['notes']))
                                        <div class="text-xs text-[#8C8474]">{{ $row['notes'] }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $row['category'] }}</td>
                                <td class="px-4 py-3 text-[#6B6459]">{{ $row['kind'] }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-medium">&#2547; {{ number_format($row['amount'], 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-[#8C8474]">
                                    No expenses recorded for this month.
                                    <a href="{{ route('admin.expenses', ['year' => $year, 'month' => $month]) }}" wire:navigate
                                        class="text-[#C9A227] hover:underline">Add expenses</a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-[#EFE7D6] bg-white">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[40rem] text-sm">
                    <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                        <tr>
                            <th class="px-4 py-3 font-medium">Order</th>
                            <th class="px-4 py-3 font-medium">Customer</th>
                            <th class="px-4 py-3 font-medium">Delivered</th>
                            @if ($metric === 'revenue')
                                <th class="px-4 py-3 font-medium text-right">Collected</th>
                            @elseif ($metric === 'direct')
                                <th class="px-4 py-3 font-medium text-right">COGS</th>
                                <th class="px-4 py-3 font-medium text-right">Pack</th>
                                <th class="px-4 py-3 font-medium text-right">Courier</th>
                                <th class="px-4 py-3 font-medium text-right">COD</th>
                                <th class="px-4 py-3 font-medium text-right">Direct</th>
                            @else
                                <th class="px-4 py-3 font-medium text-right">Revenue</th>
                                <th class="px-4 py-3 font-medium text-right">Direct</th>
                                <th class="px-4 py-3 font-medium text-right">Contribution</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E7DFCF]">
                        @forelse ($orders as $row)
                            <tr class="hover:bg-[#FAF6EF]/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.orders.show', $row['id']) }}" wire:navigate
                                        class="font-medium text-[#C9A227] hover:underline">
                                        #{{ $row['order_number'] }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">{{ $row['name'] }}</td>
                                <td class="px-4 py-3 text-[#8C8474] whitespace-nowrap">{{ $row['delivered_at'] ?? '—' }}</td>
                                @if ($metric === 'revenue')
                                    <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($row['revenue'], 0) }}</td>
                                @elseif ($metric === 'direct')
                                    <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($row['cogs'], 0) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($row['packaging'], 0) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($row['courier'], 0) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($row['cod'], 0) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums font-medium">&#2547; {{ number_format($row['direct'], 0) }}</td>
                                @else
                                    <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($row['revenue'], 0) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">&#2547; {{ number_format($row['direct'], 0) }}</td>
                                    <td @class(['px-4 py-3 text-right tabular-nums font-medium', 'text-rose-700' => $row['profit'] < 0])>
                                        &#2547; {{ number_format($row['profit'], 0) }}
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-10 text-center text-[#8C8474]">
                                    No delivered orders in this month.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
