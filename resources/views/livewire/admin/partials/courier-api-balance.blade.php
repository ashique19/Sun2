@php
    $apiBalance = $apiBalances[$courier->id] ?? null;
    $expectedApi = (float) ($summary['expected_api'] ?? $summary['receivable'] ?? 0);
    $apiDiff = $apiBalance !== null ? round((float) $apiBalance - $expectedApi, 2) : null;
@endphp

@if ($apiBalancesLoading)
    <span class="text-[#8C8474]">…</span>
@elseif ($apiBalance !== null)
    <div class="tabular-nums">&#2547; {{ number_format($apiBalance, 0) }}</div>
    <div class="mt-0.5 text-[11px] text-[#8C8474]" title="Receivable = collected − courier charge − COD charge − withdrawals">
        Should be &#2547; {{ number_format($expectedApi, 0) }}
    </div>
    @if (abs($apiDiff) < 0.5)
        <div class="mt-0.5 text-[11px] text-emerald-700" title="API − receivable">
            Diff {{ $apiDiff > 0 ? '+' : ($apiDiff < 0 ? '−' : '') }}&#2547; {{ number_format(abs($apiDiff), 0) }}
        </div>
    @else
        <button type="button"
            wire:click="openDiffOrders({{ $courier->id }})"
            title="API − receivable. Open orders that explain this Diff."
            class="mt-0.5 min-h-11 text-left text-[11px] font-semibold text-amber-700 underline decoration-amber-300 underline-offset-2 hover:text-amber-900 lg:min-h-0">
            Diff {{ $apiDiff > 0 ? '+' : '−' }}&#2547; {{ number_format(abs($apiDiff), 0) }}
        </button>
    @endif
@elseif (! $apiBalancesLoaded && $courier->slug && in_array(strtolower((string) $courier->slug), $apiSlugs, true))
    <div class="text-[#8C8474]">Tap Refresh API</div>
    <div class="mt-0.5 text-[11px] text-[#8C8474]" title="Receivable = collected − courier charge − COD charge − withdrawals">
        Should be &#2547; {{ number_format($expectedApi, 0) }}
    </div>
@elseif ($courier->slug && in_array(strtolower((string) $courier->slug), $apiSlugs, true))
    <div class="text-[#8C8474]" title="{{ $apiBalanceError ?: 'Unavailable' }}">—</div>
    <div class="mt-0.5 text-[11px] text-[#8C8474]" title="Receivable = collected − courier charge − COD charge − withdrawals">
        Should be &#2547; {{ number_format($expectedApi, 0) }}
    </div>
@else
    <span class="text-[#8C8474]">n/a</span>
@endif
