@props([
    'url',
    'excludedPaths' => [],
])

@php
    $url = (string) $url;
    $excludedPaths = array_values(array_filter(
        (array) $excludedPaths,
        fn (mixed $path): bool => is_string($path) && $path !== '',
    ));
@endphp

{{--
  Exit interstitial (not a true tab-close hook — browsers block custom UI on unload).
  Triggers: browser Back (popstate) + desktop exit-intent (cursor leaves top).
  Mounted on the app layout (outside Livewire page morphs) so wire:navigate cannot
  destroy the host mid-show or leak popstate listeners that burn sessionStorage.
--}}
<div
    wire:ignore
    data-sun-exit-interstitial
    x-data="{
        open: false,
        countdown: 5,
        holdSeconds: 2,
        timer: null,
        holdTimer: null,
        shownThisSession: false,
        enabled: true,
        storageKey: 'sun_exit_interstitial_shown',
        smartlinkUrl: @js($url),
        excludedPaths: @js($excludedPaths),
        _uid: Math.random().toString(36).slice(2, 9),
        _onNavigated: null,
        // #region agent log
        _dbg(hypothesisId, message, data) {
            try {
                fetch('/_agent/debug-log', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        hypothesisId,
                        location: 'exit-interstitial.blade.php',
                        message,
                        data: Object.assign({ uid: this._uid, href: location.href, runId: window.__sunExitRunId || 'post-fix' }, data || {}),
                        timestamp: Date.now(),
                    }),
                    keepalive: true,
                }).catch(() => {});
            } catch (e) {}
        },
        // #endregion
        init() {
            // #region agent log
            let prior = null;
            try { prior = sessionStorage.getItem(this.storageKey); } catch (e) {}
            this._dbg('E', 'init:start', { priorSession: prior, histState: history.state, histLen: history.length });
            // #endregion
            try {
                if (window.sessionStorage && sessionStorage.getItem(this.storageKey)) {
                    this.shownThisSession = true;
                }
            } catch (e) {}

            window.__sunExitHost = this;
            this.syncEnabledFromLocation();

            this._onNavigated = () => {
                this.syncEnabledFromLocation();
                if (this.enabled && ! this.shownThisSession) {
                    this.pushExitTrap();
                }
            };
            document.addEventListener('livewire:navigated', this._onNavigated);

            if (this.shownThisSession) {
                // #region agent log
                this._dbg('E', 'init:skip-already-shown', {});
                // #endregion
                return;
            }

            this.ensureGlobalListeners();
            // #region agent log
            this._dbg('A', 'init:armed', {
                enabled: this.enabled,
                listenersBound: !!window.__sunExitListenersBound,
                histHasTrap: !!(history.state && history.state.sunExitTrap),
                pointerFine: !!(window.matchMedia && window.matchMedia('(pointer: fine)').matches),
            });
            // #endregion
        },
        destroy() {
            // #region agent log
            this._dbg('A', 'destroy', { open: this.open, shown: this.shownThisSession, isHost: window.__sunExitHost === this });
            // #endregion
            this.clearTimers();
            if (this._onNavigated) {
                document.removeEventListener('livewire:navigated', this._onNavigated);
                this._onNavigated = null;
            }
            if (window.__sunExitHost === this) {
                window.__sunExitHost = null;
            }
        },
        syncEnabledFromLocation() {
            const path = location.pathname || '/';
            this.enabled = ! this.excludedPaths.some((prefix) => {
                return path === prefix || path.startsWith(prefix + '/');
            });
            // #region agent log
            this._dbg('E', 'syncEnabled', { enabled: this.enabled, path });
            // #endregion
            if (! this.enabled && this.open) {
                this.dismissWithoutLink();
            }
        },
        pathIsExcluded() {
            return ! this.enabled;
        },
        pushExitTrap() {
            if (! this.enabled || this.shownThisSession) {
                return;
            }
            try {
                const prior = (history.state && typeof history.state === 'object') ? history.state : {};
                if (prior.sunExitTrap) {
                    return;
                }
                history.pushState(Object.assign({}, prior, { sunExitTrap: 1 }), '', location.href);
            } catch (e) {}
        },
        ensureGlobalListeners() {
            this.pushExitTrap();

            if (window.__sunExitListenersBound) {
                return;
            }
            window.__sunExitListenersBound = true;

            window.addEventListener('popstate', () => {
                const host = window.__sunExitHost;
                // #region agent log
                try {
                    fetch('/_agent/debug-log', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({
                            hypothesisId: 'B',
                            location: 'exit-interstitial.blade.php',
                            message: 'popstate:global',
                            data: {
                                runId: window.__sunExitRunId || 'post-fix',
                                hasHost: !!host,
                                hostUid: host?._uid || null,
                                hostConnected: !!(host && host.$el && host.$el.isConnected),
                                enabled: host?.enabled ?? null,
                                href: location.href,
                                histState: history.state,
                            },
                            timestamp: Date.now(),
                        }),
                        keepalive: true,
                    }).catch(() => {});
                } catch (e) {}
                // #endregion
                if (! host || ! host.$el || ! host.$el.isConnected) {
                    return;
                }
                host.syncEnabledFromLocation();
                host.handlePopState();
            });

            document.addEventListener('mouseout', (event) => {
                const host = window.__sunExitHost;
                if (! host || ! host.$el || ! host.$el.isConnected) {
                    return;
                }
                host.handleExitIntent(event);
            });
        },
        handlePopState() {
            if (! this.enabled || this.shownThisSession || this.open) {
                return;
            }
            // #region agent log
            this._dbg('B', 'handlePopState', { elConnected: !!(this.$el && this.$el.isConnected) });
            // #endregion
            this.show();
            this.pushExitTrap();
        },
        handleExitIntent(event) {
            if (! this.enabled || this.shownThisSession || this.open) {
                return;
            }
            if (window.matchMedia && window.matchMedia('(pointer: fine)').matches === false) {
                return;
            }
            if (event.clientY > 0) {
                return;
            }
            if (event.relatedTarget) {
                // #region agent log
                this._dbg('D', 'exit-intent:blocked-related', { clientY: event.clientY });
                // #endregion
                return;
            }
            // #region agent log
            this._dbg('D', 'exit-intent:fire', { clientY: event.clientY });
            // #endregion
            this.show();
        },
        show() {
            if (! this.enabled || this.shownThisSession || this.open) {
                return;
            }
            if (! this.$el || ! this.$el.isConnected) {
                // #region agent log
                this._dbg('A', 'show:aborted-disconnected', {});
                // #endregion
                return;
            }
            // #region agent log
            this._dbg('B', 'show', { elConnected: true });
            // #endregion
            this.open = true;
            this.countdown = 5;
            this.markShown();
            this.startCountdown();
            // #region agent log
            this.$nextTick(() => {
                this._dbg('C', 'show:after-paint', {
                    open: this.open,
                    elConnected: !!(this.$el && this.$el.isConnected),
                    overlayDisplay: this.$refs.overlay ? getComputedStyle(this.$refs.overlay).display : null,
                });
            });
            // #endregion
        },
        markShown() {
            this.shownThisSession = true;
            try {
                if (window.sessionStorage) {
                    sessionStorage.setItem(this.storageKey, '1');
                }
            } catch (e) {}
            // #region agent log
            this._dbg('A', 'markShown', { elConnected: !!(this.$el && this.$el.isConnected) });
            // #endregion
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
>
    <div
        x-ref="overlay"
        x-cloak
        x-bind:class="open ? 'flex' : 'hidden'"
        x-transition.opacity.duration.150ms
        class="fixed inset-0 z-[180] items-center justify-center bg-[#1E1E1E]/80 p-4"
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
</div>
