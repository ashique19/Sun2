/**
 * Mobile inbox: browser / swipe Back from a thread must open the conversation
 * list — not a previously viewed thread, and not a page before Inbox.
 *
 * Safari's swipe-back skips query-only changes on the same path, so the thread
 * lives at /admin/inbox/c/{id} while the list stays at /admin/inbox.
 * Livewire wire:navigate would still restore cached HTML across those URLs
 * (shared alpine.snapshotIdx); same-document inbox restores are cancelled and
 * the list pane is shown immediately so the first swipe is not a no-op.
 */

export const MOBILE_INBOX_MQ = '(max-width: 1279px)';

const THREAD_PATH = /\/c\/(\d+)\/?$/;

export function inboxPathname(href) {
    return new URL(href, 'http://localhost').pathname.replace(/\/+$/, '') || '/';
}

export function isInboxLocation(href) {
    const path = inboxPathname(href);

    return path.endsWith('/admin/inbox') || THREAD_PATH.test(path);
}

export function inboxListUrl(href) {
    const url = new URL(href, 'http://localhost');

    url.searchParams.delete('conversation');
    url.pathname = url.pathname.replace(/\/+$/, '').replace(THREAD_PATH, '') || '/';
    url.hash = '';

    return url.toString();
}

export function inboxThreadUrl(href, conversationId) {
    const url = new URL(href, 'http://localhost');
    const base = url.pathname.replace(/\/+$/, '').replace(THREAD_PATH, '') || '/';

    url.searchParams.delete('conversation');
    url.pathname = `${base}/c/${conversationId}`;
    url.hash = '';

    return url.toString();
}

export function conversationIdFromHref(href) {
    const url = new URL(href, 'http://localhost');
    const raw = url.searchParams.get('conversation');
    if (raw !== null && raw !== '') {
        const n = Number(raw);

        return Number.isFinite(n) ? n : null;
    }

    const match = url.pathname.match(THREAD_PATH);

    return match ? Number(match[1]) : null;
}

export function isSameInboxDocument(currentHref, destination) {
    if (destination == null || destination === '') {
        return false;
    }

    const destHref = typeof destination === 'string' || destination instanceof URL
        ? destination
        : destination.href ?? destination.pathname ?? String(destination);

    try {
        return isInboxLocation(currentHref) && isInboxLocation(destHref);
    } catch {
        return false;
    }
}

export function preventSameDocumentInboxNavigate(event, currentHref) {
    const dest = event?.detail?.url;
    if (! isSameInboxDocument(currentHref, dest)) {
        return false;
    }

    event.preventDefault();

    return true;
}

export const INBOX_NAV_METHODS = ['selectConversation', 'clearConversationFromUrl', 'closeMobileThread'];

let desiredPane = null;
let desiredConversationId = null;
let lastInboxNavAction = null;

export function inboxDesiredConversationId() {
    return desiredPane === 'thread' ? desiredConversationId : null;
}

export function inboxDesiredPane() {
    return desiredPane;
}

export function setInboxDesiredThread(conversationId) {
    desiredPane = 'thread';
    const id = conversationId == null || conversationId === '' ? null : Number(conversationId);
    desiredConversationId = Number.isFinite(id) ? id : null;
    showInboxThreadPane(desiredConversationId);
}

export function setInboxDesiredList() {
    desiredPane = 'list';
    desiredConversationId = null;
    showInboxListPane();
}

export function liveThreadMatchesDesired(liveId = null) {
    const desired = inboxDesiredConversationId();
    if (desired == null) {
        return false;
    }

    const live = liveId == null
        ? Number(typeof document === 'undefined'
            ? 0
            : document.querySelector('[data-inbox-live-thread-id]')?.getAttribute('data-inbox-live-thread-id') || '0')
        : Number(liveId);

    return Number.isFinite(live) && live === desired;
}

export function shouldKeepCachedThread(liveId) {
    const desired = inboxDesiredConversationId();
    if (desired == null) {
        return false;
    }

    return Number(liveId) !== desired;
}

export function cancelStaleInboxNavigation(action, previous = lastInboxNavAction) {
    if (! action || ! INBOX_NAV_METHODS.includes(action.name)) {
        return previous;
    }

    if (previous && previous !== action && typeof previous.cancel === 'function' && ! previous.isCancelled?.()) {
        previous.cancel();
        previous.message?.cancel?.();
    }

    lastInboxNavAction = action;

    return action;
}

export function syncInboxDesiredPane(wireOpen) {
    if (typeof document === 'undefined') {
        return desiredPane;
    }

    if (desiredPane === 'thread') {
        document.body.dataset.inboxDesiredPane = 'thread';
        if (desiredConversationId != null) {
            document.body.dataset.inboxDesiredId = String(desiredConversationId);
        }
        document.body.dataset.inboxMobileThread = '1';
        delete document.body.dataset.inboxClosingThread;

        return desiredPane;
    }

    if (desiredPane === 'list') {
        document.body.dataset.inboxDesiredPane = 'list';
        delete document.body.dataset.inboxDesiredId;
        document.body.dataset.inboxMobileThread = '0';

        return desiredPane;
    }

    document.body.dataset.inboxMobileThread = wireOpen ? '1' : '0';

    return desiredPane;
}

export function showInboxListPane() {
    if (typeof document === 'undefined') {
        return;
    }

    document.body.dataset.inboxDesiredPane = 'list';
    delete document.body.dataset.inboxDesiredId;
    document.body.dataset.inboxClosingThread = '1';
    document.body.dataset.inboxMobileThread = '0';
    const sheet = document.querySelector('[data-inbox-mobile-thread-sheet]');
    sheet?.removeAttribute('data-inbox-pending-id');
    sheet?.removeAttribute('data-inbox-skip-opening');
    const cache = document.querySelector('[data-inbox-cache-thread]');
    if (cache) {
        cache.hidden = true;
        cache.classList.remove('is-hiding');
        cache.setAttribute('aria-hidden', 'true');
    }
}

export function showInboxThreadPane(conversationId) {
    if (typeof document === 'undefined') {
        return;
    }

    delete document.body.dataset.inboxClosingThread;
    document.body.dataset.inboxDesiredPane = 'thread';
    document.body.dataset.inboxMobileThread = '1';
    const sheet = document.querySelector('[data-inbox-mobile-thread-sheet]');
    if (sheet && conversationId != null) {
        document.body.dataset.inboxDesiredId = String(conversationId);
        sheet.setAttribute('data-inbox-pending-id', String(conversationId));
    }
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
    const { history, getHref, isMobile, showList = () => {}, showThread = () => {}, delay = (fn, ms) => setTimeout(fn, ms) } = env;
    let closingToList = false;
    let closeTimer = 0;
    let parkedListUrl = null;

    const armCloseLock = () => {
        closingToList = true;
        clearTimeout(closeTimer);
        closeTimer = delay(() => {
            closingToList = false;
            if (typeof document !== 'undefined' && ! wire.mobileThreadOpen) {
                delete document.body.dataset.inboxClosingThread;
            }
        }, 400);
    };

    const prepare = (mobileThreadOpen, conversationId) => {
        if (! isMobile()) {
            return;
        }

        closingToList = false;

        const href = getHref();
        const id = conversationId ?? conversationIdFromHref(href);
        desiredPane = 'thread';
        desiredConversationId = id == null || id === '' ? null : Number(id);
        showThread(id);
        const state = cloneState(history);

        if (state.inboxPane === 'thread' && (mobileThreadOpen || conversationIdFromHref(href) !== null)) {
            parkedListUrl = parkedListUrl ?? inboxListUrl(href);
            if (id != null) {
                history.replaceState({ ...state, inboxPane: 'thread' }, '', inboxThreadUrl(href, id));
            }

            return;
        }

        const listState = alpineWithoutConversation({ ...state, inboxPane: 'list' });
        const listUrl = inboxListUrl(href);
        parkedListUrl = listUrl;
        const threadHref = id != null ? inboxThreadUrl(href, id) : href;

        history.replaceState(listState, '', listUrl);
        history.pushState({ ...cloneState(history), inboxPane: 'thread' }, '', threadHref);
    };

    const parkedListHref = () => {
        if (parkedListUrl) {
            return parkedListUrl;
        }

        const href = getHref();

        return isInboxLocation(href) ? inboxListUrl(href) : null;
    };

    const parkListHistory = () => {
        const listUrl = parkedListHref();
        if (! listUrl) {
            return false;
        }

        const listState = alpineWithoutConversation({ ...cloneState(history), inboxPane: 'list' });

        if (! isInboxLocation(getHref())) {
            history.pushState(listState, '', listUrl);
        } else {
            history.replaceState(listState, '', listUrl);
        }

        return true;
    };

    const closeToList = () => {
        if (! parkListHistory()) {
            if (wire.selectedConversationId || wire.mobileThreadOpen) {
                wire.clearConversationFromUrl();
            }

            return;
        }

        if (wire.selectedConversationId || wire.mobileThreadOpen) {
            wire.clearConversationFromUrl();
        }
    };

    const onPopState = () => {
        if (! isMobile()) {
            closingToList = false;
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
        const skippedList = closingToList && ! isInboxLocation(getHref());

        if (threadOpen || skippedList) {
            desiredPane = 'list';
            desiredConversationId = null;
            showList();
            closeToList();
            armCloseLock();

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

    return { prepare, onPopState, boot, closeToList, parkListHistory };
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
        showList: showInboxListPane,
        showThread: showInboxThreadPane,
    });

    window.__inboxThreadHistoryApi = api;
    window.__inboxPrepareThreadHistory = (mobileThreadOpen, conversationId) => {
        api.prepare(mobileThreadOpen, conversationId);
    };
    window.__inboxPrepareList = () => {
        desiredPane = 'list';
        desiredConversationId = null;
        showInboxListPane();
        api.parkListHistory();
    };
    window.__inboxSyncDesiredPane = syncInboxDesiredPane;
    window.__inboxDesiredConversationId = inboxDesiredConversationId;

    const installNavInterceptor = () => {
        if (! window.Livewire?.interceptAction || window.__inboxNavInterceptor) {
            return;
        }

        window.__inboxNavInterceptor = true;
        let inflightNavMessage = null;
        const isInboxEl = (el) => Boolean(
            el?.closest?.('[data-inbox-snapshot-root]') || el?.hasAttribute?.('data-inbox-snapshot-root'),
        );
        const isNavMessage = (message) => {
            const actions = typeof message.getActions === 'function'
                ? message.getActions()
                : [...(message.actions || [])];

            return actions.some((action) => INBOX_NAV_METHODS.includes(action.name));
        };

        window.Livewire.interceptAction(({ action }) => {
            if (! INBOX_NAV_METHODS.includes(action.name) || ! isInboxEl(action.component?.el)) {
                return;
            }

            if (inflightNavMessage && ! inflightNavMessage.isCancelled?.()) {
                inflightNavMessage.cancel();
                inflightNavMessage = null;
            }
        });

        if (typeof window.Livewire.interceptMessage === 'function') {
            window.Livewire.interceptMessage(({ message, onFinish, onCancel }) => {
                if (! isNavMessage(message) || ! isInboxEl(message.component?.el)) {
                    return;
                }

                inflightNavMessage = message;
                const clear = () => {
                    if (inflightNavMessage === message) {
                        inflightNavMessage = null;
                    }
                };
                onFinish(clear);
                onCancel(clear);
            });
        }
    };

    installNavInterceptor();
    document.addEventListener('livewire:init', installNavInterceptor);

    if (! window.__inboxThreadHistoryListener) {
        window.__inboxThreadHistoryListener = true;
        window.addEventListener('popstate', () => {
            window.__inboxThreadHistoryApi?.onPopState();
        });
        document.addEventListener('alpine:navigate', (event) => {
            if (preventSameDocumentInboxNavigate(event, window.location.href)) {
                return;
            }

            if (document.body.dataset.inboxClosingThread === '1' && event.detail?.url && ! isInboxLocation(event.detail.url)) {
                event.preventDefault();
            }
        });
    }

    api.boot();

    return api;
}
