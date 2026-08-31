@props([
    'url',
])

@php
    $url = (string) $url;
@endphp

{{--
  Exit interstitial (not a true tab-close hook — browsers block custom UI on unload).
  Triggers: browser Back (popstate) + desktop exit-intent (cursor leaves top).
  Facebook in-app browser: the WebView X button cannot be intercepted; Back may work.
--}}
<div
    x-data="{
        open: false,
        countdown: 5,
        holdSeconds: 2,
        timer: null,
        holdTimer: null,
        shownThisSession: false,
        storageKey: 'sun_exit_interstitial_shown',
        smartlinkUrl: @js($url),
        init() {
            try {
                if (window.sessionStorage && sessionStorage.getItem(this.storageKey)) {
                    this.shownThisSession = true;
                }
            } catch (e) {}
            if (this.shownThisSession) {
                return;
            }
            this.armBackTrap();
            this.armExitIntent();
        },
        armBackTrap() {
            try {
                history.pushState({ sunExitTrap: 1 }, '', location.href);
            } catch (e) {}
            window.addEventListener('popstate', () => {
                if (this.shownThisSession || this.open) {
                    return;
                }
                this.show();
                try {
                    history.pushState({ sunExitTrap: 1 }, '', location.href);
                } catch (e) {}
            });
        },
        armExitIntent() {
            if (window.matchMedia && window.matchMedia('(pointer: fine)').matches === false) {
                return;
            }
            document.addEventListener('mouseout', (event) => {
                if (this.shownThisSession || this.open) {
                    return;
                }
                if (event.clientY > 0) {
                    return;
                }
                if (event.relatedTarget || event.toElement) {
                    return;
                }
                this.show();
            });
        },
        show() {
            if (this.shownThisSession || this.open) {
                return;
            }
            this.open = true;
            this.countdown = 5;
            this.markShown();
            this.startCountdown();
        },
        markShown() {
            this.shownThisSession = true;
            try {
                if (window.sessionStorage) {
                    sessionStorage.setItem(this.storageKey, '1');
                }
            } catch (e) {}
        },
        startCountdown() {
            this.clearTimers();
            this.timer = setInterval(() => {
                if (this.countdown > 0) {
                    this.countdown -= 1;
                    return;
                }
                clearInterval(this.timer);
                this.timer = null;
                this.holdTimer = setTimeout(() => {
                    this.dismissWithoutLink();
                }, this.holdSeconds * 1000);
            }, 1000);
        },
        clearTimers() {
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
            if (this.holdTimer) {
                clearTimeout(this.holdTimer);
                this.holdTimer = null;
            }
        },
        closeNow() {
            this.clearTimers();
            const popup = window.open(this.smartlinkUrl, '_blank');
            this.open = false;
            try {
                window.close();
            } catch (e) {}
            if (! popup) {
                window.location.href = this.smartlinkUrl;
            }
        },
        dismissWithoutLink() {
            this.clearTimers();
            this.open = false;
        },
    }"
    x-cloak
    x-show="open"
    class="fixed inset-0 z-[180] flex items-center justify-center bg-[#1E1E1E]/80 p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="sun-exit-interstitial-title"
    @keydown.escape.window="if (open) dismissWithoutLink()"
>
    <div class="w-full max-w-md rounded-2xl border border-[#E0D6C2] bg-[#FAF6EF] px-6 py-8 text-center shadow-xl">
        <p id="sun-exit-interstitial-title" class="font-serif text-xl font-semibold text-[#1E1E1E] sm:text-2xl">
            <span x-show="countdown > 0" x-text="'{{ __('storefront.exit_securely_closing') }} ' + countdown + '…'"></span>
            <span x-show="countdown === 0" x-cloak>{{ __('storefront.exit_securely_closing_zero') }}</span>
        </p>
        <p class="mt-2 text-sm text-[#6B6459]">{{ __('storefront.exit_interstitial_hint') }}</p>

        <button
            type="button"
            class="mt-6 inline-flex w-full items-center justify-center rounded-full bg-[#8F7218] px-6 py-3.5 text-base font-bold uppercase tracking-wide text-white hover:bg-[#7A6114] transition"
            @click="closeNow()"
        >
            {{ __('storefront.exit_close_now') }}
        </button>
    </div>
</div>
