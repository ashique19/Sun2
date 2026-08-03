<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Steadfast webhooks</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Newest inbound update per order · last 2 days
            </p>
        </div>
        <a href="{{ route('admin.dashboard') }}"
            wire:navigate
            class="inline-flex items-center rounded-lg border border-[#E7DFCF] px-4 py-2 text-sm font-medium text-[#6B6459] transition hover:border-[#C9A227] hover:text-[#1E1E1E]">
            &larr; Back to Dashboard
        </a>
    </div>

    <div class="overflow-hidden rounded-xl border border-[#E0D6C2] bg-white">
        @if ($entries->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-[#8C8474]">No inbound Steadfast webhooks in the last 2 days.</p>
        @else
            <div class="divide-y divide-[#EFE7D6]">
                @foreach ($entries as $entry)
                    @php
                        $order = $entry->order;
                        $payload = is_array($entry->api_data) ? $entry->api_data : [];
                        $notificationType = (string) ($payload['notification_type'] ?? 'update');
                        $summary = $summaries[$entry->id] ?? 'webhook update';
                        $steadfastUrl = $order?->steadfastConsignmentUrl();
                        $parcelId = $order?->printParcelId();
                    @endphp
                    <div wire:key="steadfast-webhook-page-{{ $entry->id }}" class="flex flex-wrap items-start gap-3 px-4 py-3 hover:bg-[#FAF6EF]/50">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-[#FAF6EF] text-[#6B6459]">
                                    {{ str_replace('_', ' ', $notificationType) }}
                                </span>
                                @if ($order)
                                    <a href="{{ route('admin.orders.show', $order) }}" wire:navigate
                                        class="text-xs font-medium text-[#C9A227] hover:underline">
                                        Order #{{ $order->order_number }}
                                    </a>
                                    <span class="truncate text-xs text-[#6B6459]">{{ $order->name }}</span>
                                @else
                                    <span class="text-xs text-[#8C8474]">Order unavailable</span>
                                @endif
                                @if ($entry->created_at)
                                    <span class="text-[10px] text-[#8C8474]">{{ $entry->created_at->diffForHumans() }}</span>
                                @endif
                            </div>
                            <p class="mt-1 line-clamp-2 text-sm text-[#1E1E1E]">{{ $summary }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-[#8C8474]">
                                @if ($order?->status)
                                    <span class="font-medium text-[#6B6459]">{{ $order->status }}</span>
                                @endif
                                @if ($steadfastUrl)
                                    <a href="{{ $steadfastUrl }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-medium text-[#C9A227] hover:underline"
                                        title="Open Steadfast consignment">
                                        Parcel {{ $parcelId }} ↗
                                    </a>
                                @elseif ($parcelId)
                                    <span>Parcel {{ $parcelId }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-col gap-1.5">
                            @if ($order)
                                <a href="{{ route('admin.orders.show', $order) }}" wire:navigate
                                    class="inline-flex items-center justify-center rounded px-2.5 py-1 text-xs font-medium text-white bg-[#C9A227] hover:bg-[#B8921F]">
                                    Order
                                </a>
                            @endif
                            <button type="button"
                                wire:click="dismiss({{ $entry->id }})"
                                class="inline-flex items-center justify-center rounded border border-[#E7DFCF] px-2.5 py-1 text-xs font-medium text-[#6B6459] hover:border-[#C9A227] hover:text-[#1E1E1E]">
                                Dismiss
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($entries->hasPages())
                <div class="border-t border-[#EFE7D6] px-4 py-3">
                    {{ $entries->links() }}
                </div>
            @endif
        @endif
    </div>
</div>
