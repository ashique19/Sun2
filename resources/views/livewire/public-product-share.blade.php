<div class="share-print-root mx-auto max-w-3xl px-4 py-6 sm:px-6">
    <header class="mb-6 flex items-center justify-between gap-3 border-b border-[#E7DFCF] pb-4">
        <a href="{{ route('home') }}" class="flex items-center gap-2 min-w-0">
            <img src="/img/settings/logo.png" alt="Sundoritoma" class="h-10 w-auto object-contain">
            <span class="font-serif text-lg font-semibold truncate">Sundoritoma</span>
        </a>
        <a href="tel:01880001255" class="shrink-0 text-sm font-medium text-[#C9A227] hover:underline">
            {{ __('storefront.helpline_label') }}: 01880001255
        </a>
    </header>

    @if ($expired)
        <div class="rounded-xl border border-[#EFE7D6] bg-white p-8 text-center">
            <h1 class="font-serif text-2xl font-semibold">{{ __('storefront.share_expired_title') }}</h1>
            <p class="mt-2 text-sm text-[#6B6459]">{{ __('storefront.share_expired_body') }}</p>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                <a href="{{ route('home') }}"
                   class="inline-block rounded-full bg-[#C9A227] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                    {{ __('storefront.go_home') }}
                </a>
                <a href="tel:01880001255"
                   class="inline-block rounded-full border border-[#C9A227] px-6 py-2.5 text-sm font-semibold text-[#C9A227] hover:bg-[#FAF6EF]">
                    {{ __('storefront.call_us') }}
                </a>
            </div>
        </div>
    @else
        <div class="mb-4">
            <h1 class="font-serif text-2xl font-semibold">{{ __('storefront.share_title') }}</h1>
            <p class="mt-1 text-sm text-[#6B6459]">{{ __('storefront.share_purpose') }}</p>
            @if ($share?->expires_at)
                <p class="mt-1 text-sm text-[#8C8474]">
                    {{ __('storefront.share_valid_until', ['date' => $share->expires_at->timezone('Asia/Dhaka')->format('d M Y, h:i A')]) }}
                </p>
            @endif
        </div>

        @if ($items === [])
            <div class="rounded-xl border border-[#EFE7D6] bg-white p-8 text-center">
                <p class="text-sm text-[#8C8474]">{{ __('storefront.share_empty') }}</p>
                <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                    <a href="{{ route('home') }}"
                       class="inline-block rounded-full bg-[#C9A227] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                        {{ __('storefront.go_home') }}
                    </a>
                    <a href="tel:01880001255"
                       class="inline-block rounded-full border border-[#C9A227] px-6 py-2.5 text-sm font-semibold text-[#C9A227] hover:bg-[#FAF6EF]">
                        {{ __('storefront.call_us') }}
                    </a>
                </div>
            </div>
        @else
            @php
                $lineCount = count($items);
                $pcsCount = (int) collect($items)->sum('quantity');
            @endphp

            <div class="sticky top-0 z-10 -mx-4 mb-4 border-b border-[#E7DFCF] bg-[#FAF6EF]/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-xl sm:border sm:border-[#EFE7D6] sm:bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm font-medium tabular-nums text-[#1E1E1E]">
                        {{ __('storefront.share_lines_pcs', ['lines' => number_format($lineCount), 'pcs' => number_format($pcsCount)]) }}
                    </p>
                    <button type="button" onclick="window.print()"
                        class="share-no-print rounded-full border border-[#E0D6C2] bg-white px-4 py-1.5 text-xs font-semibold hover:bg-[#FAF6EF]">
                        {{ __('storefront.print_list') }}
                    </button>
                </div>
            </div>

            <div class="space-y-3">
                @foreach ($items as $item)
                    @php
                        $thumb = ! empty($item['image'])
                            ? (\App\Support\StorefrontAssets::smallUrl($item['image'])
                                ?? \App\Support\StorefrontAssets::variantUrl($item['image'], 'xs')
                                ?? $item['image'])
                            : null;
                    @endphp
                    <div wire:key="share-item-{{ $item['key'] }}"
                        class="flex items-start gap-3 rounded-xl border border-[#EFE7D6] bg-white p-3 sm:gap-4 sm:p-4">
                        <div class="h-20 w-20 shrink-0 overflow-hidden rounded-lg border border-[#E7DFCF] bg-[#FAF6EF] sm:h-24 sm:w-24">
                            @if ($thumb)
                                <img src="{{ $thumb }}"
                                    alt="{{ $item['name'] ?? '' }}"
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                    decoding="async">
                            @else
                                <div class="flex h-full w-full items-center justify-center text-[10px] text-[#8C8474]">
                                    {{ __('storefront.share_no_img') }}
                                </div>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="line-clamp-3 text-sm font-medium text-[#1E1E1E]" title="{{ $item['name'] ?? '' }}">
                                {{ $item['name'] ?? '' }}
                            </p>
                            <p class="mt-1 text-xl font-semibold tabular-nums text-[#1E1E1E]">
                                &times;{{ number_format((int) ($item['quantity'] ?? 0)) }}
                            </p>
                        </div>

                        @if ($canManage)
                            <button type="button"
                                wire:click="removeRow('{{ $item['key'] }}')"
                                wire:confirm="{{ __('storefront.share_remove_confirm') }}"
                                class="share-no-print shrink-0 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                {{ __('storefront.delete') }}
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
