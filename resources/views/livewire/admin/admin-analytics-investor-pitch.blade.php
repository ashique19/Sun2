@php
    $t = $deck['traction'];
    $p = $deck['prior'];
    $g = $deck['growth'];
    $u = $deck['unit_economics'];
    $maxMonthlyGmv = max(
        1,
        (float) (collect($deck['monthly'])->max('gmv') ?: 0),
        (float) (collect($deck['monthly'])->max('prior_gmv') ?: 0),
    );

    $fmt = fn (?float $n) => $n === null ? '—' : '৳'.number_format($n, 0);
    $pct = function (?float $n, bool $signed = true): string {
        if ($n === null) {
            return '—';
        }
        $prefix = $signed && $n > 0 ? '+' : '';

        return $prefix.number_format($n, 1).'%';
    };
    $growthTone = fn (?float $n) => match (true) {
        $n === null => 'text-[#8C8474]',
        $n > 0 => 'text-[#2F6F4E]',
        $n < 0 => 'text-rose-700',
        default => 'text-[#6B6459]',
    };
@endphp

<div class="investor-pitch">
    <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
        <div>
            <div class="mb-1 flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('admin.analytics') }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">Analytics</a>
                <span class="text-[#8C8474]">/</span>
                <span class="text-[#6B6459]">Investor pitch</span>
            </div>
            <h1 class="font-serif text-3xl font-semibold">Investor pitch deck</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Yearly report from orders · compared with prior year · as of {{ $deck['as_of'] }}
            </p>
        </div>
        <button type="button" wire:click="refreshDeck"
            class="rounded-full border border-[#E0D6C2] px-4 py-2 text-xs font-medium text-[#6B6459] hover:bg-[#FAF6EF]">
            Refresh now
        </button>
    </div>

    <x-admin.analytics-subnav active="investor" />

    <div class="mb-5 flex flex-wrap items-center gap-2" role="group" aria-label="Report year">
        <span class="mr-1 text-xs font-medium text-[#8C8474]">Year</span>
        @foreach ($years as $y)
            <button type="button" wire:click="selectYear({{ $y }})"
                @class([
                    'rounded-full px-3 py-1.5 text-xs font-medium transition border tabular-nums',
                    'border-[#C9A227] bg-[#FAF6EF] text-[#1E1E1E]' => (int) $year === (int) $y,
                    'border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227]' => (int) $year !== (int) $y,
                ])>
                {{ $y }}
            </button>
        @endforeach
    </div>

    {{-- Slide 1: Brand + headline traction --}}
    <section class="mb-6 overflow-hidden rounded-2xl border border-[#EFE7D6] bg-gradient-to-br from-[#FAF6EF] via-white to-[#F3EBD8] p-6 sm:p-8">
        <p class="text-xs font-semibold tracking-[0.2em] text-[#C9A227] uppercase">Sundoritoma</p>
        <h2 class="mt-2 max-w-2xl font-serif text-3xl font-semibold text-[#1E1E1E] sm:text-4xl">
            Expansion-ready COD jewelry commerce
        </h2>
        <p class="mt-3 max-w-xl text-sm text-[#6B6459]">
            {{ $deck['window']['label'] }} ({{ $deck['window']['start'] }} → {{ $deck['window']['end'] }})
            vs {{ $deck['prior_window']['label'] }}
            · Bangladesh mobile-first · cash on delivery
            @if ($deck['is_partial_year'])
                <span class="ml-1 rounded-full bg-white/80 px-2 py-0.5 text-[11px] text-[#C9A227] ring-1 ring-[#EFE7D6]">YTD</span>
            @endif
        </p>

        <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl bg-white/80 p-4 ring-1 ring-[#EFE7D6]">
                <p class="text-xs text-[#8C8474]">Placed GMV</p>
                <p class="mt-1 font-serif text-2xl font-semibold tabular-nums">{{ $fmt($t['gmv_placed']) }}</p>
                <p class="mt-1 text-xs {{ $growthTone($g['gmv_placed_pct']) }}">{{ $pct($g['gmv_placed_pct']) }} vs {{ $deck['prior_year'] }}</p>
            </div>
            <div class="rounded-xl bg-white/80 p-4 ring-1 ring-[#EFE7D6]">
                <p class="text-xs text-[#8C8474]">Orders</p>
                <p class="mt-1 font-serif text-2xl font-semibold tabular-nums">{{ number_format($t['orders']) }}</p>
                <p class="mt-1 text-xs {{ $growthTone($g['orders_pct']) }}">{{ $pct($g['orders_pct']) }} vs {{ $deck['prior_year'] }} · AOV {{ $fmt($t['aov']) }}</p>
            </div>
            <div class="rounded-xl bg-white/80 p-4 ring-1 ring-[#EFE7D6]">
                <p class="text-xs text-[#8C8474]">Collected (delivered)</p>
                <p class="mt-1 font-serif text-2xl font-semibold tabular-nums">{{ $fmt($t['collected']) }}</p>
                <p class="mt-1 text-xs text-[#6B6459]">{{ number_format($t['delivered']) }} delivered · {{ $t['collection_pct'] }}% of face</p>
            </div>
            <div class="rounded-xl bg-white/80 p-4 ring-1 ring-[#EFE7D6]">
                <p class="text-xs text-[#8C8474]">Return rate</p>
                <p class="mt-1 font-serif text-2xl font-semibold tabular-nums">{{ number_format($t['return_pct'], 1) }}%</p>
                <p class="mt-1 text-xs text-[#6B6459]">{{ number_format($t['unique_buyers']) }} unique buyers</p>
            </div>
        </div>
    </section>

    {{-- Slide 2: Growth --}}
    <section class="mb-6 rounded-2xl border border-[#EFE7D6] bg-white p-6">
        <h2 class="font-serif text-xl font-semibold">{{ $deck['year'] }} vs {{ $deck['prior_year'] }}</h2>
        <p class="mt-1 text-sm text-[#8C8474]">
            {{ $deck['window']['label'] }} compared with {{ $deck['prior_window']['label'] }}
            ({{ $deck['prior_window']['start'] }} → {{ $deck['prior_window']['end'] }})
        </p>
        <div class="mt-5 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b border-[#EFE7D6] text-left text-xs text-[#8C8474]">
                        <th class="py-2 pr-4 font-medium">Metric</th>
                        <th class="py-2 pr-4 font-medium">{{ $deck['window']['label'] }}</th>
                        <th class="py-2 pr-4 font-medium">{{ $deck['prior_window']['label'] }}</th>
                        <th class="py-2 font-medium">Change</th>
                    </tr>
                </thead>
                <tbody class="tabular-nums">
                    @foreach ([
                        ['Orders', number_format($t['orders']), number_format($p['orders']), $g['orders_pct']],
                        ['Placed GMV', $fmt($t['gmv_placed']), $fmt($p['gmv_placed']), $g['gmv_placed_pct']],
                        ['Delivered', number_format($t['delivered']), number_format($p['delivered']), $g['delivered_pct']],
                        ['Collected', $fmt($t['collected']), $fmt($p['collected']), $g['collected_pct']],
                        ['AOV', $fmt($t['aov']), $fmt($p['aov']), $g['aov_pct']],
                    ] as [$label, $cur, $pri, $chg])
                        <tr class="border-b border-[#F5F0E6]">
                            <td class="py-2.5 pr-4 text-[#6B6459]">{{ $label }}</td>
                            <td class="py-2.5 pr-4 font-medium text-[#1E1E1E]">{{ $cur }}</td>
                            <td class="py-2.5 pr-4 text-[#8C8474]">{{ $pri }}</td>
                            <td class="py-2.5 font-medium {{ $growthTone($chg) }}">{{ $pct($chg) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{-- Slide 3: Unit economics --}}
    <section class="mb-6 rounded-2xl border border-[#EFE7D6] bg-white p-6">
        <h2 class="font-serif text-xl font-semibold">Unit economics</h2>
        <p class="mt-1 text-sm text-[#8C8474]">Delivered in {{ $deck['window']['label'] }} · contribution before ads &amp; salaries</p>
        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl bg-[#FAF6EF] p-4">
                <p class="text-xs text-[#8C8474]">Merch GM (known COGS)</p>
                <p class="mt-1 font-serif text-2xl font-semibold tabular-nums">
                    {{ $u['gm_pct_known'] === null ? '—' : number_format($u['gm_pct_known'], 1).'%' }}
                </p>
                <p class="mt-1 text-xs text-[#6B6459]">{{ number_format($u['cogs_coverage_pct'], 1) }}% line coverage</p>
            </div>
            <div class="rounded-xl bg-[#FAF6EF] p-4">
                <p class="text-xs text-[#8C8474]">Merch GP (est.)</p>
                <p class="mt-1 font-serif text-2xl font-semibold tabular-nums">{{ $fmt($u['merch_gp_est']) }}</p>
                <p class="mt-1 text-xs text-[#6B6459]">on {{ $fmt($u['merch_sell']) }} sell</p>
            </div>
            <div class="rounded-xl bg-[#FAF6EF] p-4">
                <p class="text-xs text-[#8C8474]">Delivery margin</p>
                <p class="mt-1 font-serif text-2xl font-semibold tabular-nums">{{ $fmt($u['delivery_margin']) }}</p>
                <p class="mt-1 text-xs text-[#6B6459]">{{ $fmt($u['delivery_income']) }} − {{ $fmt($u['courier_cost']) }} courier</p>
            </div>
            <div class="rounded-xl bg-[#FAF6EF] p-4">
                <p class="text-xs text-[#8C8474]">Contribution (est.)</p>
                <p class="mt-1 font-serif text-2xl font-semibold tabular-nums">{{ $fmt($u['contribution_est']) }}</p>
                <p class="mt-1 text-xs text-[#6B6459]">
                    {{ $u['contribution_pct_of_collected'] === null ? '—' : number_format($u['contribution_pct_of_collected'], 1).'%' }}
                    of collected
                </p>
            </div>
        </div>
    </section>

    {{-- Slide 4: Monthly pulse with prior year --}}
    <section class="mb-6 rounded-2xl border border-[#EFE7D6] bg-white p-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="font-serif text-xl font-semibold">Monthly pulse</h2>
                <p class="mt-1 text-sm text-[#8C8474]">Placed GMV by month · {{ $deck['year'] }} vs {{ $deck['prior_year'] }}</p>
            </div>
            <div class="flex items-center gap-3 text-[11px] text-[#6B6459]">
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-[#C9A227]"></span>{{ $deck['year'] }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-[#E0D6C2]"></span>{{ $deck['prior_year'] }}</span>
            </div>
        </div>
        <div class="mt-5 space-y-3">
            @foreach ($deck['monthly'] as $row)
                <div class="grid grid-cols-[2.5rem_1fr_auto] items-center gap-3 text-xs">
                    <span class="text-[#6B6459]">{{ $row['label'] }}</span>
                    <div class="space-y-1">
                        <div class="h-2 overflow-hidden rounded-full bg-[#F5F0E6]">
                            <div class="h-full rounded-full bg-[#C9A227]"
                                style="width: {{ min(100, round($row['gmv'] * 100 / $maxMonthlyGmv, 1)) }}%"></div>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-[#F5F0E6]">
                            <div class="h-full rounded-full bg-[#E0D6C2]"
                                style="width: {{ min(100, round($row['prior_gmv'] * 100 / $maxMonthlyGmv, 1)) }}%"></div>
                        </div>
                    </div>
                    <span class="min-w-[8.5rem] text-right tabular-nums text-[#1E1E1E]">
                        {{ $fmt($row['gmv']) }}
                        <span class="text-[#8C8474]">/ {{ $fmt($row['prior_gmv']) }}</span>
                    </span>
                </div>
            @endforeach
        </div>
    </section>
    <div class="mb-6 grid gap-6 lg:grid-cols-3">
        {{-- Channels --}}
        <section class="rounded-2xl border border-[#EFE7D6] bg-white p-6">
            <h2 class="font-serif text-lg font-semibold">Order channel</h2>
            <ul class="mt-4 space-y-3 text-sm">
                @forelse ($deck['channels'] as $row)
                    <li class="flex items-center justify-between gap-3">
                        <span class="text-[#6B6459]">{{ $row['via'] }}</span>
                        <span class="tabular-nums text-[#1E1E1E]">
                            {{ number_format($row['share_pct'], 1) }}%
                            <span class="text-[#8C8474]">· {{ $row['orders'] }}</span>
                        </span>
                    </li>
                @empty
                    <li class="text-[#8C8474]">No orders in window.</li>
                @endforelse
            </ul>
        </section>

        {{-- Geo --}}
        <section class="rounded-2xl border border-[#EFE7D6] bg-white p-6">
            <h2 class="font-serif text-lg font-semibold">Top cities</h2>
            <ul class="mt-4 space-y-3 text-sm">
                @forelse ($deck['geos'] as $row)
                    <li class="flex items-center justify-between gap-3">
                        <span class="text-[#6B6459]">{{ $row['city'] }}</span>
                        <span class="tabular-nums text-[#1E1E1E]">
                            {{ $row['orders'] }}
                            <span class="text-[#8C8474]">· {{ $fmt($row['gmv']) }}</span>
                        </span>
                    </li>
                @empty
                    <li class="text-[#8C8474]">No orders in window.</li>
                @endforelse
            </ul>
        </section>

        {{-- Categories --}}
        <section class="rounded-2xl border border-[#EFE7D6] bg-white p-6">
            <h2 class="font-serif text-lg font-semibold">Top categories</h2>
            <p class="text-xs text-[#8C8474]">Delivered line revenue</p>
            <ul class="mt-4 space-y-3 text-sm">
                @forelse ($deck['categories'] as $row)
                    <li class="flex items-center justify-between gap-3">
                        <span class="truncate text-[#6B6459]">{{ $row['name'] }}</span>
                        <span class="shrink-0 tabular-nums text-[#1E1E1E]">{{ $fmt($row['revenue']) }}</span>
                    </li>
                @empty
                    <li class="text-[#8C8474]">No delivered lines.</li>
                @endforelse
            </ul>
        </section>
    </div>

    {{-- Ops health --}}
    <section class="mb-6 rounded-2xl border border-[#EFE7D6] bg-white p-6">
        <h2 class="font-serif text-xl font-semibold">Ops health</h2>
        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-[#EFE7D6] p-4">
                <p class="text-xs text-[#8C8474]">Unsettled dispatched</p>
                <p class="mt-1 font-serif text-xl font-semibold tabular-nums">{{ number_format($t['dispatched']) }}</p>
                <p class="mt-1 text-xs text-[#6B6459]">{{ $fmt($t['unsettled_gmv']) }} face value in pipe</p>
            </div>
            <div class="rounded-xl border border-[#EFE7D6] p-4">
                <p class="text-xs text-[#8C8474]">Collection on delivered</p>
                <p class="mt-1 font-serif text-xl font-semibold tabular-nums">{{ number_format($t['collection_pct'], 1) }}%</p>
                <p class="mt-1 text-xs text-[#6B6459]">collected ÷ delivered face GMV</p>
            </div>
            <div class="rounded-xl border border-[#EFE7D6] p-4">
                <p class="text-xs text-[#8C8474]">Returns</p>
                <p class="mt-1 font-serif text-xl font-semibold tabular-nums">{{ number_format($t['returned']) }}</p>
                <p class="mt-1 text-xs text-[#6B6459]">{{ number_format($t['return_pct'], 1) }}% of placed orders</p>
            </div>
        </div>
    </section>

    {{-- Caveats --}}
    <section class="mb-2 rounded-2xl border border-dashed border-[#E0D6C2] bg-[#FAF6EF]/60 p-6">
        <h2 class="font-serif text-lg font-semibold">Methodology notes</h2>
        <ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm text-[#6B6459]">
            @foreach ($deck['caveats'] as $caveat)
                <li>{{ $caveat }}</li>
            @endforeach
        </ul>
        <div class="mt-4 flex flex-wrap gap-3 text-xs">
            <a href="{{ route('admin.analytics.pnl') }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">Open P&amp;L →</a>
            <a href="{{ route('admin.analytics.ordered-delivered') }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">Ordered vs delivered →</a>
            <a href="{{ route('admin.analytics.category-revenue') }}" wire:navigate class="font-medium text-[#C9A227] hover:underline">By category →</a>
        </div>
    </section>
</div>
