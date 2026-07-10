@props([
    'openEvent' => 'open-product-image',
    'closeEvent' => 'close-product-image',
    'linkTarget' => '_blank',
    'linkLabel' => 'View product on store',
    'showExternalIcon' => true,
])

<div
    x-data="{
        open: false,
        imageUrl: '',
        productUrl: '',
        productName: '',
        show(detail) {
            const data = Array.isArray(detail) ? (detail[0] || {}) : (detail || {});
            this.imageUrl = data.imageUrl || '';
            this.productUrl = data.productUrl || '';
            this.productName = data.productName || '';
            this.open = true;
            document.body.classList.add('overflow-hidden');
        },
        hide() {
            this.open = false;
            this.imageUrl = '';
            this.productUrl = '';
            this.productName = '';
            document.body.classList.remove('overflow-hidden');
        },
    }"
    x-on:{{ $openEvent }}.window="show($event.detail)"
    x-on:{{ $closeEvent }}.window="hide()"
    x-on:keydown.escape.window="if (open) hide()"
    x-cloak
>
    <template x-teleport="body">
        <template x-if="open">
            <div
                class="fixed inset-0 z-[200] grid h-dvh w-screen grid-rows-[auto_1fr_auto] bg-black/95"
                x-on:click.self="hide()"
                role="dialog"
                aria-modal="true"
            >
                <div class="flex items-center justify-between gap-4 px-4 py-3 text-white sm:px-6">
                    <p class="truncate text-sm font-medium sm:text-base" x-text="productName"></p>
                    <button type="button" x-on:click="hide()"
                        class="shrink-0 rounded-full border border-white/30 px-4 py-1.5 text-sm hover:bg-white/10 transition">
                        Close
                    </button>
                </div>

                <div class="flex min-h-0 items-center justify-center overflow-hidden px-4 py-2 sm:px-8">
                    <img
                        :src="imageUrl"
                        :alt="productName"
                        class="block max-h-[calc(100dvh-10rem)] max-w-[min(96vw,56rem)] object-contain"
                    >
                </div>

                <div class="px-4 pb-6 pt-2 text-center sm:px-6" x-show="productUrl">
                    <a
                        :href="productUrl"
                        @if ($linkTarget) target="{{ $linkTarget }}" @endif
                        @if ($linkTarget === '_blank') rel="noopener" @endif
                        class="inline-flex items-center gap-2 rounded-full bg-[#C9A227] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f] transition"
                    >
                        {{ $linkLabel }}
                        @if ($showExternalIcon)
                            <span aria-hidden="true">↗</span>
                        @endif
                    </a>
                </div>
            </div>
        </template>
    </template>
</div>
