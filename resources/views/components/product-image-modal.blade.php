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
        imageUrls: [],
        imageIndex: 0,
        productUrl: '',
        productName: '',
        linkLabel: @js($linkLabel),
        linkTarget: @js($linkTarget),
        defaultLinkLabel: @js($linkLabel),
        defaultLinkTarget: @js($linkTarget),
        showExternalIcon: @js((bool) $showExternalIcon),
        touchStartX: 0,
        show(detail) {
            const data = Array.isArray(detail) ? (detail[0] || {}) : (detail || {});
            const fromList = Array.isArray(data.imageUrls)
                ? data.imageUrls.filter((url) => !! url)
                : [];
            const urls = fromList.length > 0
                ? fromList
                : (data.imageUrl ? [data.imageUrl] : []);
            if (urls.length === 0) {
                return;
            }
            this.imageUrls = urls;
            this.imageIndex = 0;
            this.imageUrl = urls[0];
            this.productUrl = data.productUrl || '';
            this.productName = data.productName || '';
            this.linkLabel = data.linkLabel || this.defaultLinkLabel;
            this.linkTarget = (data.linkTarget !== undefined && data.linkTarget !== null)
                ? data.linkTarget
                : this.defaultLinkTarget;
            this.open = true;
            document.body.classList.add('overflow-hidden');
        },
        hide() {
            this.open = false;
            this.imageUrl = '';
            this.imageUrls = [];
            this.imageIndex = 0;
            this.productUrl = '';
            this.productName = '';
            this.linkLabel = this.defaultLinkLabel;
            this.linkTarget = this.defaultLinkTarget;
            document.body.classList.remove('overflow-hidden');
        },
        currentUrl() {
            return this.imageUrls[this.imageIndex] || this.imageUrl;
        },
        hasMany() {
            return this.imageUrls.length > 1;
        },
        goTo(index) {
            if (! this.hasMany()) {
                return;
            }
            const total = this.imageUrls.length;
            this.imageIndex = ((index % total) + total) % total;
            this.imageUrl = this.imageUrls[this.imageIndex];
        },
        next() {
            this.goTo(this.imageIndex + 1);
        },
        prev() {
            this.goTo(this.imageIndex - 1);
        },
        onSwipeEnd(event) {
            if (! this.hasMany()) {
                return;
            }
            const dx = event.changedTouches[0].screenX - this.touchStartX;
            if (Math.abs(dx) < 40) {
                return;
            }
            dx < 0 ? this.next() : this.prev();
        },
    }"
    x-on:{{ $openEvent }}.window="show($event.detail)"
    x-on:{{ $closeEvent }}.window="hide()"
    x-on:keydown.escape.window="if (open) hide()"
    x-on:keydown.arrow-left.window="if (open) prev()"
    x-on:keydown.arrow-right.window="if (open) next()"
    x-cloak
>
    <template x-teleport="body">
        <template x-if="open">
            <div
                class="fixed inset-0 z-[200] grid h-dvh w-screen grid-rows-[auto_1fr_auto] bg-black/95"
                x-on:click.self="hide()"
                role="dialog"
                aria-modal="true"
                data-product-image-modal
            >
                <div class="flex items-center justify-between gap-4 px-4 py-3 text-white sm:px-6">
                    <p class="truncate text-sm font-medium sm:text-base" x-text="productName"></p>
                    <button type="button" x-on:click="hide()"
                        class="shrink-0 rounded-full border border-white/30 px-4 py-1.5 text-sm hover:bg-white/10 transition">
                        Close
                    </button>
                </div>

                <div
                    class="relative flex min-h-0 items-center justify-center overflow-hidden px-4 py-2 sm:px-8"
                    data-product-image-slider
                    x-on:touchstart="touchStartX = $event.changedTouches[0].screenX"
                    x-on:touchend="onSwipeEnd($event)"
                >
                    <img
                        :src="currentUrl()"
                        :alt="productName"
                        class="block max-h-[calc(100dvh-10rem)] max-w-[min(96vw,56rem)] select-none object-contain"
                        draggable="false"
                    >

                    <button
                        type="button"
                        x-show="hasMany()"
                        x-on:click.stop="prev()"
                        class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full border border-white/30 bg-black/45 px-3 py-2 text-lg leading-none text-white hover:bg-white/15 sm:left-4"
                        aria-label="Previous image"
                    >
                        ‹
                    </button>
                    <button
                        type="button"
                        x-show="hasMany()"
                        x-on:click.stop="next()"
                        class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full border border-white/30 bg-black/45 px-3 py-2 text-lg leading-none text-white hover:bg-white/15 sm:right-4"
                        aria-label="Next image"
                    >
                        ›
                    </button>
                </div>

                <div class="px-4 pb-6 pt-2 text-center sm:px-6">
                    <div class="mb-3 flex flex-col items-center gap-2" x-show="hasMany()">
                        <p class="text-xs tabular-nums text-white/70" x-text="(imageIndex + 1) + ' / ' + imageUrls.length"></p>
                        <div class="flex flex-wrap items-center justify-center gap-1.5">
                            <template x-for="(url, i) in imageUrls" :key="url + '-' + i">
                                <button
                                    type="button"
                                    x-on:click.stop="goTo(i)"
                                    class="h-2 w-2 rounded-full transition"
                                    :class="i === imageIndex ? 'bg-white' : 'bg-white/40 hover:bg-white/70'"
                                    :aria-current="i === imageIndex ? 'true' : 'false'"
                                    :aria-label="'Image ' + (i + 1)"
                                ></button>
                            </template>
                        </div>
                    </div>

                    <a
                        x-show="productUrl"
                        :href="productUrl"
                        :target="linkTarget || null"
                        :rel="linkTarget === '_blank' ? 'noopener' : null"
                        data-product-image-details-link
                        class="inline-flex items-center gap-2 rounded-full bg-[#8F7218] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#7A6114] transition"
                    >
                        <span x-text="linkLabel">{{ $linkLabel }}</span>
                        <span x-show="showExternalIcon && linkTarget === '_blank'" aria-hidden="true">↗</span>
                    </a>
                </div>
            </div>
        </template>
    </template>
</div>
