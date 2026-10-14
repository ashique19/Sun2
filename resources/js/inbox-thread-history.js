/**
 * Mobile inbox: browser / swipe Back from a thread must open the conversation
 * list — not a previously viewed thread, and not a page before Inbox.
 *
 * Livewire replaceState-s ?conversation=, which overwrites the list history
 * entry unless we first park a list URL and push a distinct thread URL.
 * popstate used to $wire.set the popped ?conversation= (that reopened the
 * previous thread).
 */

export const MOBILE_INBOX_MQ = '(max-width: 1279px)';

export function inboxListUrl(href) {
    const url = new URL(href, 'http://localhost');

    url.searchParams.delete('conversation');

    return url.toString();
}

export function inboxThreadUrl(href, conversationId) {
    const url = new URL(href, 'http://localhost');

    url.searchParams.set('conversation', String(conversationId));

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

export function inboxPathname(href) {
    return new URL(href, 'http://localhost').pathname.replace(/\/+$/, '') || '/';
}

export function isSameInboxDocument(currentHref, destination) {
    if (destination == null || destination === '') {
        return false;
    }

    const destHref = typeof destination === 'string' || destination instanceof URL
        ? destination
        : destination.href ?? destination.pathname ?? String(destination);

    try {
        return inboxPathname(currentHref) === inboxPathname(destHref)
            && inboxPathname(currentHref).endsWith('/admin/inbox');
    } catch {
        return false;
    }
}

/**
 * Livewire wire:navigate restores cached HTML on popstate whenever
 * history.state.alpine.snapshotIdx is set. List and thread share that key, so
 * the first Back saves the open thread over the list snapshot and the next
 * Back restores the previous conversation. Cancel same-document inbox
 * restores; clearConversationFromUrl morphs the list instead.
 */
export function preventSameDocumentInboxNavigate(event, currentHref) {
    const dest = event?.detail?.url;
    if (! isSameInboxDocument(currentHref, dest)) {
        return false;
    }

    event.preventDefault();

    return true;
}

function cloneState(history) {
    return history.state && typeof history.state === 'object' ? { ...history.state } : {};
}

function alpineWithoutConversation(state) {
    if (! state.alpine || typeof state.alpine !== 'object') {
        return state;
    }

    return {
        ...state,
        alpine: {
            ...state.alpine,
            conversation: { value: null },
        },
    };
}

export function createInboxThreadHistory(wire, env) {
    const { history, getHref, isMobile } = env;

    const prepare = (mobileThreadOpen, conversationId) => {
        if (! isMobile()) {
            return;
        }

        const href = getHref();
        const id = conversationId ?? conversationIdFromHref(href);
        const state = cloneState(history);

        if (state.inboxPane === 'thread' && (mobileThreadOpen || conversationIdFromHref(href) !== null)) {
            return;
        }

        const listState = alpineWithoutConversation({ ...state, inboxPane: 'list' });
        const listUrl = inboxListUrl(href);
        const threadHref = id != null ? inboxThreadUrl(href, id) : href;

        history.replaceState(listState, '', listUrl);
        history.pushState({ ...cloneState(history), inboxPane: 'thread' }, '', threadHref);
    };

    const closeToList = () => {
        const listState = alpineWithoutConversation({ ...cloneState(history), inboxPane: 'list' });
        history.replaceState(listState, '', inboxListUrl(getHref()));

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

        const threadOpen = Boolean(wire.mobileThreadOpen) || Boolean(wire.selectedConversationId);
        const fromUrl = conversationIdFromHref(getHref());

        if (threadOpen) {
            if (fromUrl !== null || cloneState(history).inboxPane !== 'list') {
                const listState = alpineWithoutConversation({ ...cloneState(history), inboxPane: 'list' });
                history.replaceState(listState, '', inboxListUrl(getHref()));
            }

            wire.clearConversationFromUrl();

            return;
        }

        if (fromUrl !== null) {
            const listState = alpineWithoutConversation({ ...cloneState(history), inboxPane: 'list' });
            history.replaceState(listState, '', inboxListUrl(getHref()));
        }
    };

    const boot = () => {
        if (! isMobile()) {
            return;
        }

        if (! wire.mobileThreadOpen && conversationIdFromHref(getHref()) === null) {
            return;
        }

        if (cloneState(history).inboxPane === 'thread') {
            return;
        }

        prepare(false, wire.selectedConversationId ?? conversationIdFromHref(getHref()));
    };

    return { prepare, onPopState, boot, closeToList };
}

export function installInboxThreadHistory(wire) {
    if (typeof window === 'undefined') {
        return null;
    }

    window.__inboxThreadHistoryWire = wire;

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

    window.__inboxThreadHistoryApi = api;
    window.__inboxPrepareThreadHistory = (mobileThreadOpen, conversationId) => {
        api.prepare(mobileThreadOpen, conversationId);
    };

    if (! window.__inboxThreadHistoryListener) {
        window.__inboxThreadHistoryListener = true;
        window.addEventListener('popstate', () => {
            window.__inboxThreadHistoryApi?.onPopState();
        });
        document.addEventListener('alpine:navigate', (event) => {
            preventSameDocumentInboxNavigate(event, window.location.href);
        });
    }

    api.boot();

    return api;
}
