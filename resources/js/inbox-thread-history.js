/**
 * Mobile inbox: browser / swipe Back from a thread must open the conversation
 * list — not a previously viewed thread, and not a page before Inbox.
 *
 * Livewire replaceState-s ?conversation=, which overwrites the list history
 * entry unless we first push a thread overlay. popstate used to $wire.set the
 * conversation id from the popped URL, which reopened the previous thread.
 */

export const MOBILE_INBOX_MQ = '(max-width: 1279px)';

export function inboxListUrl(href) {
    const url = new URL(href, 'http://localhost');

    url.searchParams.delete('conversation');

    return url.toString();
}

export function conversationIdFromHref(href) {
    const raw = new URL(href, 'http://localhost').searchParams.get('conversation');
    if (raw === null || raw === '') {
        return null;
    }

    const n = Number(raw);

    return Number.isFinite(n) ? n : null;
}

function cloneState(history) {
    return history.state && typeof history.state === 'object' ? { ...history.state } : {};
}

export function createInboxThreadHistory(wire, env) {
    const { history, getHref, isMobile } = env;
    let booted = false;
    let handlingPop = false;

    const prepare = (mobileThreadOpen) => {
        if (! isMobile()) {
            return;
        }

        if (cloneState(history).inboxPane === 'thread' && mobileThreadOpen) {
            return;
        }

        history.pushState({ ...cloneState(history), inboxPane: 'thread' }, '', getHref());
    };

    const closeToList = () => {
        history.replaceState({ ...cloneState(history), inboxPane: 'list' }, '', inboxListUrl(getHref()));

        if (wire.selectedConversationId || wire.mobileThreadOpen) {
            wire.clearConversationFromUrl();
        }
    };

    const onPopState = () => {
        if (! isMobile()) {
            const fromUrl = conversationIdFromHref(getHref());
            const current = wire.selectedConversationId === null
                || wire.selectedConversationId === undefined
                || wire.selectedConversationId === ''
                ? null
                : Number(wire.selectedConversationId);

            if (current !== fromUrl) {
                wire.set('selectedConversationId', fromUrl);
            }

            return;
        }

        if (handlingPop) {
            return;
        }

        handlingPop = true;

        try {
            const threadOpen = Boolean(wire.mobileThreadOpen) || Boolean(wire.selectedConversationId);
            const fromUrl = conversationIdFromHref(getHref());

            if (threadOpen) {
                if (fromUrl !== null || cloneState(history).inboxPane !== 'list') {
                    history.replaceState({ ...cloneState(history), inboxPane: 'list' }, '', inboxListUrl(getHref()));
                }

                wire.clearConversationFromUrl();

                return;
            }

            if (fromUrl !== null) {
                history.replaceState({ ...cloneState(history), inboxPane: 'list' }, '', inboxListUrl(getHref()));
            }
        } finally {
            handlingPop = false;
        }
    };

    const boot = () => {
        if (booted) {
            return;
        }

        booted = true;

        if (! isMobile()) {
            return;
        }

        if (! wire.mobileThreadOpen && conversationIdFromHref(getHref()) === null) {
            return;
        }

        if (cloneState(history).inboxPane === 'thread') {
            return;
        }

        const threadHref = getHref();
        history.replaceState({ ...cloneState(history), inboxPane: 'list' }, '', inboxListUrl(threadHref));
        history.pushState({ ...cloneState(history), inboxPane: 'thread' }, '', threadHref);
    };

    return { prepare, onPopState, boot, closeToList };
}

export function installInboxThreadHistory(wire) {
    if (typeof window === 'undefined') {
        return null;
    }

    window.__inboxThreadHistoryWire = wire;

    if (window.__inboxThreadHistoryBound) {
        window.__inboxPrepareThreadHistory = (mobileThreadOpen) => {
            window.__inboxThreadHistoryApi?.prepare(mobileThreadOpen);
        };

        return window.__inboxThreadHistoryApi;
    }

    const liveWire = {
        get mobileThreadOpen() {
            return window.__inboxThreadHistoryWire?.mobileThreadOpen;
        },
        get selectedConversationId() {
            return window.__inboxThreadHistoryWire?.selectedConversationId;
        },
        set(key, value) {
            return window.__inboxThreadHistoryWire?.set(key, value);
        },
        clearConversationFromUrl() {
            return window.__inboxThreadHistoryWire?.clearConversationFromUrl();
        },
    };

    const api = createInboxThreadHistory(liveWire, {
        history: window.history,
        getHref: () => window.location.href,
        isMobile: () => window.matchMedia(MOBILE_INBOX_MQ).matches,
    });

    window.__inboxThreadHistoryBound = true;
    window.__inboxThreadHistoryApi = api;
    window.__inboxPrepareThreadHistory = (mobileThreadOpen) => api.prepare(mobileThreadOpen);
    window.addEventListener('popstate', () => api.onPopState());
    api.boot();

    return api;
}
