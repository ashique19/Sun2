@props([
    'orders' => [],
    'acknowledge' => 'acknowledgeOpenNew',
    'merge' => 'mergeIntoOpenNew',
    'sourceId' => null,
    'canMerge' => true,
])

@if ($orders !== [])
    <div {{ $attributes->class('rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950') }} data-open-new-order-offer>
        <p class="font-semibold">This phone already has a New order — acknowledge it or merge to avoid a duplicate.</p>
        <ul class="mt-2 space-y-2">
            @foreach ($orders as $openNew)
                <li class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between" wire:key="open-new-{{ $openNew['id'] }}">
                    <p class="min-w-0">
                        <a href="{{ route('admin.orders.show', $openNew['id']) }}" wire:navigate class="font-semibold text-[#C9A227] hover:underline">
                            #{{ $openNew['order_number'] }}
                        </a>
                        <span class="text-amber-900/80">
                            · ৳{{ number_format($openNew['total'], 0) }}
                            · {{ $openNew['item_count'] }} {{ str('item')->plural($openNew['item_count']) }}
                            @if ($openNew['name'] !== '')
                                · {{ $openNew['name'] }}
                            @endif
                        </span>
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button"
                            wire:click="{{ $acknowledge }}({{ $openNew['id'] }})"
                            class="inline-flex h-8 items-center rounded-lg border border-amber-300 bg-white px-2.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                            Acknowledge
                        </button>
                        @if ($canMerge)
                            <button type="button"
                                @if ($sourceId)
                                    wire:click="{{ $merge }}({{ $sourceId }}, {{ $openNew['id'] }})"
                                @else
                                    wire:click="{{ $merge }}({{ $openNew['id'] }})"
                                @endif
                                wire:confirm="Merge into New order #{{ $openNew['order_number'] }} and skip creating a duplicate?"
                                class="inline-flex h-8 items-center rounded-lg bg-[#C9A227] px-2.5 text-xs font-semibold text-white hover:bg-[#b89220]">
                            Merge
                            </button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif
