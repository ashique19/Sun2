<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Steadfast webhooks</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Every inbound POST after auth · unmatched, empty, and duplicates included
            </p>
        </div>
        <a href="{{ route('admin.dashboard') }}"
            wire:navigate
            class="inline-flex items-center rounded-lg border border-[#E7DFCF] px-4 py-2 text-sm font-medium text-[#6B6459] transition hover:border-[#C9A227] hover:text-[#1E1E1E]">
            &larr; Back to Dashboard
        </a>
    </div>

    <div class="overflow-hidden rounded-xl border border-[#E0D6C2] bg-white">
        @if ($logs->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-[#8C8474]">No inbound Steadfast webhooks saved yet.</p>
        @else
            <div class="divide-y divide-[#EFE7D6]">
                @foreach ($logs as $log)
                    @php
                        $order = $log->order;
                        $outcomeStyles = match ($log->outcome) {
                            'processed' => 'bg-emerald-50 text-emerald-800',
                            'unmatched' => 'bg-amber-50 text-amber-800',
                            'duplicate' => 'bg-sky-50 text-sky-800',
                            'failed' => 'bg-red-50 text-red-800',
                            default => 'bg-[#FAF6EF] text-[#6B6459]',
                        };
                    @endphp
                    <div wire:key="steadfast-webhook-log-{{ $log->id }}" class="px-4 py-3">
                        <div class="flex flex-wrap items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium {{ $outcomeStyles }}">
                                        {{ $log->outcome }}
                                    </span>
                                    @if ($log->notification_type)
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-[#FAF6EF] text-[#6B6459]">
                                            {{ str_replace('_', ' ', $log->notification_type) }}
                                        </span>
                                    @endif
                                    @if ($order)
                                        <a href="{{ route('admin.orders.show', $order) }}" wire:navigate
                                            class="text-xs font-medium text-[#C9A227] hover:underline">
                                            Order #{{ $order->order_number }}
                                        </a>
                                        <span class="truncate text-xs text-[#6B6459]">{{ $order->name }}</span>
                                    @elseif ($log->invoice)
                                        <span class="text-xs text-[#6B6459]">invoice {{ $log->invoice }}</span>
                                    @else
                                        <span class="text-xs text-[#8C8474]">No order match</span>
                                    @endif
                                    @if ($log->created_at)
                                        <span class="text-[10px] text-[#8C8474]">{{ $log->created_at->diffForHumans() }}</span>
                                    @endif
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-[#8C8474]">
                                    <span>HTTP {{ $log->http_status }}</span>
                                    @if ($log->consignment_id)
                                        <span>consignment {{ $log->consignment_id }}</span>
                                    @endif
                                    @if ($log->tracking_id)
                                        <span>tracking {{ $log->tracking_id }}</span>
                                    @endif
                                    @if ($log->idempotency_key)
                                        <span class="truncate">idemp {{ $log->idempotency_key }}</span>
                                    @endif
                                </div>
                                @if ($log->error)
                                    <p class="mt-1 text-xs text-red-700">{{ $log->error }}</p>
                                @endif
                            </div>
                            @if ($order)
                                <a href="{{ route('admin.orders.show', $order) }}" wire:navigate
                                    class="inline-flex shrink-0 items-center justify-center rounded px-2.5 py-1 text-xs font-medium text-white bg-[#C9A227] hover:bg-[#B8921F]">
                                    Order
                                </a>
                            @endif
                        </div>
                        <details class="mt-2">
                            <summary class="cursor-pointer text-xs font-medium text-[#C9A227] hover:text-[#B8921F]">Raw payload</summary>
                            <pre class="mt-2 max-h-80 overflow-auto rounded bg-[#1E1E1E] p-3 font-mono text-[11px] leading-5 text-white">{{ $log->prettyPayload() }}</pre>
                        </details>
                    </div>
                @endforeach
            </div>

            @if ($logs->hasPages())
                <div class="border-t border-[#EFE7D6] px-4 py-3">
                    {{ $logs->links() }}
                </div>
            @endif
        @endif
    </div>
</div>
