<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
    @if ($expired)
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-8 text-center">
            <h1 class="font-serif text-2xl font-semibold">Link expired</h1>
            <p class="mt-2 text-sm text-[#6B6459]">This product list is no longer available. Ask for a new link.</p>
        </div>
    @else
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="font-serif text-2xl font-semibold">Product list</h1>
                <p class="mt-1 text-sm text-[#6B6459]">
                    Valid until {{ $share->expires_at->timezone('Asia/Dhaka')->format('d M Y, h:i A') }} (Dhaka)
                </p>
            </div>
            <p class="text-sm tabular-nums text-[#6B6459]">
                {{ number_format(count($items)) }} line{{ count($items) === 1 ? '' : 's' }}
                · {{ number_format(collect($items)->sum('quantity')) }} pcs
            </p>
        </div>

        @if ($items === [])
            <div class="rounded-xl border border-[#EFE7D6] bg-white p-8 text-center text-sm text-[#8C8474]">
                No products left on this list.
            </div>
        @else
            <div class="space-y-3">
                @foreach ($items as $item)
                    <div wire:key="share-item-{{ $item['key'] }}"
                        class="flex items-center gap-4 rounded-xl border border-[#EFE7D6] bg-white p-3 sm:p-4">
                        <div class="h-36 w-36 shrink-0 overflow-hidden rounded-lg border border-[#E7DFCF] bg-[#FAF6EF] sm:h-44 sm:w-44">
                            @if (! empty($item['image']))
                                <img src="{{ \App\Support\StorefrontAssets::mediumUrl($item['image']) ?? $item['image'] }}"
                                    alt="{{ $item['name'] ?? 'Product' }}"
                                    class="h-full w-full object-cover">
                            @else
                                <div class="flex h-full w-full items-center justify-center text-xs text-[#8C8474]">No img</div>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-[#1E1E1E]" title="{{ $item['name'] ?? '' }}">
                                {{ $item['name'] ?? 'Product' }}
                            </p>
                            <p class="mt-1 text-2xl font-semibold tabular-nums text-[#1E1E1E]">
                                &times;{{ number_format((int) ($item['quantity'] ?? 0)) }}
                            </p>
                        </div>

                        @if ($canManage)
                            <button type="button"
                                wire:click="removeRow('{{ $item['key'] }}')"
                                wire:confirm="Remove this product from the list?"
                                class="shrink-0 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                Delete
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
