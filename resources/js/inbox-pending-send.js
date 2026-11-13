/**
 * Instant Messenger-style sending dots in the open thread.
 * Shown on Price / album / priced-image clicks before Livewire or Graph returns.
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
    const scroller = () => {
        if (typeof options.scroller === 'function') {
            return options.scroller();
        }

        if (typeof document === 'undefined') {
            return null;
        }

        return document.querySelector('[data-inbox-thread-scroller]');
    };

    const paint = () => {
        const el = host();
        if (! el) {
            return;
        }

        const id = liveConversationId();
        const count = id > 0 ? (counts.get(id) || 0) : 0;
        const show = count > 0;
        el.hidden = ! show;
        el.setAttribute('aria-hidden', show ? 'false' : 'true');

        if (show) {
            const thread = scroller();
            if (thread) {
                thread.scrollTop = thread.scrollHeight;
            }
        }
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
        paint();

        return next;
    };

    const countFor = (conversationId) => counts.get(Number(conversationId)) || 0;

    const isLivewireSend = (message) => {
        const actions = typeof message?.getActions === 'function'
            ? message.getActions()
            : [...(message?.actions || [])];

        return actions.some((action) => LIVEWIRE_SENDS.includes(action?.name));
    };

    const install = (root = typeof document === 'undefined' ? null : document) => {
        if (! root || root.__inboxPendingSendInstalled) {
            return;
        }

        root.__inboxPendingSendInstalled = true;

        root.addEventListener('click', (event) => {
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
            if (! window.Livewire?.interceptMessage || window.__inboxPendingSendInterceptor) {
                return;
            }

            window.__inboxPendingSendInterceptor = true;
            window.Livewire.interceptMessage(({ message, onFinish, onCancel }) => {
                if (! isLivewireSend(message)) {
                    return;
                }

                const id = liveConversationId();
                let settled = false;
                const finish = () => {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    end(id);
                    paint();
                };
                onFinish(finish);
                onCancel(finish);
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

    return { begin, end, paint, countFor, install, counts };
}

export const inboxPendingSend = createInboxPendingSend();

export const beginInboxPendingSend = (conversationId) => inboxPendingSend.begin(conversationId);
export const endInboxPendingSend = (conversationId) => inboxPendingSend.end(conversationId);
export const paintInboxPendingSend = () => inboxPendingSend.paint();

if (typeof document !== 'undefined') {
    inboxPendingSend.install(document);
}
