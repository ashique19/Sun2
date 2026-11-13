/**
 * Instant Messenger-style sending dots in the open thread.
 * Shown on Price / album / priced-image clicks before Livewire or Graph returns.
 * Graph failures keep the bubble with Retry until the operator retries or removes it.
 */

const LIVEWIRE_SENDS = [
    'sendMatchedProductPriceReply',
    'sendMatchedProductAlbumImages',
    'sendProductPickerPrice',
    'sendProductPickerAlbumImages',
];

export function conversationIdFromButton(button) {
    const raw = button?.getAttribute?.('data-conversation-id')
        || button?.closest?.('[data-inbox-priced-image-conversation]')
            ?.getAttribute('data-inbox-priced-image-conversation')
        || button?.closest?.('[data-inbox-live-thread-id]')
            ?.getAttribute('data-inbox-live-thread-id')
        || (typeof document === 'undefined'
            ? ''
            : document.querySelector('[data-inbox-live-thread-id]')?.getAttribute('data-inbox-live-thread-id'));

    const id = Number(raw || '0');

    return Number.isInteger(id) && id > 0 ? id : 0;
}

export function createInboxPendingSend(options = {}) {
    const counts = new Map();
    const failures = new Map();
    const lastLivewireSends = new Map();

    const liveConversationId = () => {
        if (typeof options.conversationId === 'function') {
            return Number(options.conversationId() || 0);
        }

        if (typeof document === 'undefined') {
            return 0;
        }

        return Number(
            document.querySelector('[data-inbox-live-thread-id]')?.getAttribute('data-inbox-live-thread-id') || '0',
        );
    };
    const host = () => {
        if (typeof options.host === 'function') {
            return options.host();
        }

        if (typeof document === 'undefined') {
            return null;
        }

        return document.querySelector('[data-inbox-pending-dots]');
    };
    const failedHost = () => {
        if (typeof options.failedHost === 'function') {
            return options.failedHost();
        }

        if (typeof document === 'undefined') {
            return null;
        }

        return document.querySelector('[data-inbox-failed-send]');
    };
    const failedMessage = () => {
        if (typeof options.failedMessage === 'function') {
            return options.failedMessage();
        }

        return failedHost()?.querySelector?.('[data-inbox-failed-send-message]') || null;
    };
    const scroller = () => {
        if (typeof options.scroller === 'function') {
            return options.scroller();
        }

        if (typeof document === 'undefined') {
            return null;
        }

        return document.querySelector('[data-inbox-thread-scroller]');
    };
    const readError = () => {
        if (typeof options.readError === 'function') {
            return String(options.readError() || '').trim();
        }

        if (typeof document === 'undefined' || ! window.Livewire) {
            return '';
        }

        const el = document.querySelector('[data-inbox-snapshot-root]');
        const id = el?.getAttribute('wire:id');
        const wire = id ? window.Livewire.find(id) : null;
        const error = wire?.get?.('error');

        return typeof error === 'string' && error.trim() !== '' ? error.trim() : '';
    };

    const paint = () => {
        const dots = host();
        const failedEl = failedHost();
        const id = liveConversationId();
        const count = id > 0 ? (counts.get(id) || 0) : 0;
        const failed = id > 0 ? failures.get(id) : null;
        const showDots = count > 0;
        const showFailed = ! showDots && Boolean(failed);

        if (dots) {
            dots.hidden = ! showDots;
            dots.setAttribute('aria-hidden', showDots ? 'false' : 'true');
        }

        if (failedEl) {
            failedEl.hidden = ! showFailed;
            failedEl.setAttribute('aria-hidden', showFailed ? 'false' : 'true');
            const message = failedMessage();
            if (message && failed) {
                message.textContent = failed.error || "Couldn't send";
            }
        }

        if (showDots || showFailed) {
            const thread = scroller();
            if (thread) {
                thread.scrollTop = thread.scrollHeight;
            }
        }
    };

    const decrement = (conversationId) => {
        const id = Number(conversationId || liveConversationId() || 0);
        if (! Number.isInteger(id) || id <= 0) {
            return 0;
        }

        const next = Math.max(0, (counts.get(id) || 0) - 1);
        if (next === 0) {
            counts.delete(id);
        } else {
            counts.set(id, next);
        }

        return next;
    };

    const begin = (conversationId) => {
        const id = Number(conversationId || liveConversationId() || 0);
        if (! Number.isInteger(id) || id <= 0) {
            return 0;
        }

        const next = (counts.get(id) || 0) + 1;
        counts.set(id, next);
        paint();

        return next;
    };

    const end = (conversationId) => {
        const next = decrement(conversationId);
        paint();

        return next;
    };

    const fail = (conversationId, error, retry = null) => {
        const id = Number(conversationId || liveConversationId() || 0);
        if (! Number.isInteger(id) || id <= 0) {
            return 0;
        }

        decrement(id);
        failures.set(id, {
            error: String(error || "Couldn't send"),
            retry,
        });
        paint();

        return counts.get(id) || 0;
    };

    const retry = (conversationId) => {
        const id = Number(conversationId || liveConversationId() || 0);
        if (! Number.isInteger(id) || id <= 0) {
            return false;
        }

        const entry = failures.get(id);
        if (! entry) {
            return false;
        }

        failures.delete(id);
        paint();
        if (typeof entry.retry === 'function') {
            entry.retry();
        }

        return true;
    };

    const dismiss = (conversationId) => {
        const id = Number(conversationId || liveConversationId() || 0);
        if (! Number.isInteger(id) || id <= 0) {
            return false;
        }

        if (! failures.has(id)) {
            return false;
        }

        failures.delete(id);
        paint();

        return true;
    };

    const countFor = (conversationId) => counts.get(Number(conversationId)) || 0;
    const failedFor = (conversationId) => failures.get(Number(conversationId)) || null;

    const isLivewireSend = (message) => {
        const actions = typeof message?.getActions === 'function'
            ? message.getActions()
            : [...(message?.actions || [])];

        return actions.some((action) => LIVEWIRE_SENDS.includes(action?.name));
    };

    const rememberLivewireSend = (action) => {
        if (! LIVEWIRE_SENDS.includes(action?.name)) {
            return;
        }

        const id = liveConversationId();
        if (! Number.isInteger(id) || id <= 0) {
            return;
        }

        lastLivewireSends.set(id, {
            name: action.name,
            params: Array.isArray(action.params) ? [...action.params] : [],
            component: action.component,
        });
    };

    const retryLastLivewire = (id) => {
        const last = lastLivewireSends.get(id);
        if (! last?.component || ! last.name) {
            return;
        }

        begin(id);
        last.component.call(last.name, ...(last.params || []));
    };

    const install = (root = typeof document === 'undefined' ? null : document) => {
        if (! root || root.__inboxPendingSendInstalled) {
            return;
        }

        root.__inboxPendingSendInstalled = true;

        root.addEventListener('click', (event) => {
            const retryButton = event.target?.closest?.('[data-inbox-failed-send-retry]');
            if (retryButton) {
                event.preventDefault();
                retry(liveConversationId());

                return;
            }

            const dismissButton = event.target?.closest?.('[data-inbox-failed-send-dismiss]');
            if (dismissButton) {
                event.preventDefault();
                dismiss(liveConversationId());

                return;
            }

            const button = event.target?.closest?.('[data-inbox-instant-send]');
            if (! button || button.disabled) {
                return;
            }

            if (button.hasAttribute('data-inbox-priced-image-send')
                || button.hasAttribute('data-inbox-priced-image-send-all')) {
                return;
            }

            begin(conversationIdFromButton(button));
        }, true);

        const hookLivewire = () => {
            if (! window.Livewire || window.__inboxPendingSendInterceptor) {
                return;
            }

            window.__inboxPendingSendInterceptor = true;

            window.Livewire.interceptAction?.(({ action }) => {
                rememberLivewireSend(action);
            });

            window.Livewire.interceptMessage(({ message, onFinish, onCancel }) => {
                if (! isLivewireSend(message)) {
                    return;
                }

                const id = liveConversationId();
                let settled = false;
                const finish = (cancelled) => {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    if (! cancelled) {
                        const error = readError();
                        if (error) {
                            fail(id, error, () => retryLastLivewire(id));

                            return;
                        }
                    }
                    end(id);
                    paint();
                };
                onFinish(() => finish(false));
                onCancel(() => finish(true));
            });

            window.Livewire.hook?.('commit', ({ succeed }) => {
                succeed(() => queueMicrotask(paint));
            });
        };

        if (typeof window !== 'undefined') {
            if (window.Livewire) {
                hookLivewire();
            }
            document.addEventListener('livewire:init', hookLivewire);
            document.addEventListener('livewire:navigated', paint);
        }
    };

    return { begin, end, fail, retry, dismiss, paint, countFor, failedFor, install, counts, failures };
}

export const inboxPendingSend = createInboxPendingSend();

export const beginInboxPendingSend = (conversationId) => inboxPendingSend.begin(conversationId);
export const endInboxPendingSend = (conversationId) => inboxPendingSend.end(conversationId);
export const failInboxPendingSend = (conversationId, error, retry) => inboxPendingSend.fail(conversationId, error, retry);
export const paintInboxPendingSend = () => inboxPendingSend.paint();

if (typeof document !== 'undefined') {
    inboxPendingSend.install(document);
}
