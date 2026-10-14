import assert from 'node:assert/strict';
import test from 'node:test';
import {
    conversationIdFromHref,
    createInboxThreadHistory,
    inboxListUrl,
    inboxThreadUrl,
    isSameInboxDocument,
    preventSameDocumentInboxNavigate,
} from '../../resources/js/inbox-thread-history.js';

function fakeHistory(initialHref, initialState = {}) {
    const stack = [{ href: initialHref, state: { ...initialState } }];
    let index = 0;

    return {
        get state() {
            return stack[index].state;
        },
        get href() {
            return stack[index].href;
        },
        urls() {
            return stack.map((entry) => entry.href);
        },
        pushState(state, _title, url) {
            stack.splice(index + 1);
            stack.push({ href: String(url), state: { ...state } });
            index = stack.length - 1;
        },
        replaceState(state, _title, url) {
            stack[index] = { href: String(url), state: { ...state } };
        },
        back() {
            if (index === 0) {
                return false;
            }

            index -= 1;

            return true;
        },
    };
}

function fakeWire(openId = null) {
    return {
        mobileThreadOpen: openId !== null,
        selectedConversationId: openId,
        set(key, value) {
            this[key] = value;
            if (key === 'selectedConversationId') {
                this.mobileThreadOpen = value !== null;
            }
        },
        clearConversationFromUrl() {
            this.selectedConversationId = null;
            this.mobileThreadOpen = false;
        },
    };
}

function mobileApi(wire, history) {
    return createInboxThreadHistory(wire, {
        history,
        getHref: () => history.href,
        isMobile: () => true,
    });
}

test('list url drops the conversation query', () => {
    assert.equal(
        inboxListUrl('https://example.test/admin/inbox?conversation=9&unread=1'),
        'https://example.test/admin/inbox?unread=1',
    );
    assert.equal(
        inboxThreadUrl('https://example.test/admin/inbox?unread=1', 9),
        'https://example.test/admin/inbox?unread=1&conversation=9',
    );
    assert.equal(conversationIdFromHref('https://example.test/admin/inbox?conversation=9'), 9);
    assert.equal(conversationIdFromHref('https://example.test/admin/inbox'), null);
});

test('opening a thread from the list parks a distinct list URL under the thread', () => {
    const history = fakeHistory('https://example.test/admin/inbox', { alpine: { snapshotIdx: 1 } });
    const wire = fakeWire(null);
    const api = mobileApi(wire, history);

    api.prepare(false, 11);
    wire.set('selectedConversationId', 11);

    assert.equal(history.state.inboxPane, 'thread');
    assert.equal(history.urls().length, 2);
    assert.equal(conversationIdFromHref(history.urls()[0]), null);
    assert.equal(conversationIdFromHref(history.urls()[1]), 11);
    assert.equal(history.urls()[0], 'https://example.test/admin/inbox');
    assert.equal(history.state.alpine.snapshotIdx, 1);

    history.back();
    api.onPopState();

    assert.equal(wire.selectedConversationId, null);
    assert.equal(wire.mobileThreadOpen, false);
    assert.equal(conversationIdFromHref(history.href), null);
    assert.match(history.href, /\/admin\/inbox$/);
});

test('swipe Back from a thread does not leave Inbox for the referrer', () => {
    const history = fakeHistory('https://example.test/admin/dashboard', { alpine: { snapshotIdx: 'dash' } });
    history.pushState({ alpine: { snapshotIdx: 'inbox' } }, '', 'https://example.test/admin/inbox');

    const wire = fakeWire(null);
    const api = mobileApi(wire, history);

    api.prepare(false, 4292);
    wire.set('selectedConversationId', 4292);

    assert.equal(history.urls().length, 3);
    assert.match(history.urls()[0], /\/admin\/dashboard$/);
    assert.equal(conversationIdFromHref(history.urls()[1]), null);
    assert.equal(conversationIdFromHref(history.urls()[2]), 4292);

    history.back();
    api.onPopState();

    assert.equal(wire.selectedConversationId, null);
    assert.equal(wire.mobileThreadOpen, false);
    assert.equal(conversationIdFromHref(history.href), null);
    assert.match(history.href, /\/admin\/inbox$/);
    assert.match(history.urls()[0], /\/admin\/dashboard$/);
});

test('swipe Back from a thread never reopens the previous conversation', () => {
    const history = fakeHistory('https://example.test/admin/inbox');
    history.pushState({}, '', 'https://example.test/admin/inbox?conversation=1');
    history.pushState({}, '', 'https://example.test/admin/inbox?conversation=2');

    const wire = fakeWire(2);
    const api = mobileApi(wire, history);

    history.back();
    assert.equal(conversationIdFromHref(history.href), 1);

    api.onPopState();

    assert.equal(wire.selectedConversationId, null);
    assert.equal(wire.mobileThreadOpen, false);
    assert.equal(conversationIdFromHref(history.href), null);
    assert.equal(history.state.inboxPane, 'list');
});

test('Back from the list ignores leftover conversation URLs instead of reopening them', () => {
    const history = fakeHistory('https://example.test/admin/inbox');
    history.pushState({}, '', 'https://example.test/admin/inbox?conversation=4');

    const wire = fakeWire(null);
    const api = mobileApi(wire, history);

    api.onPopState();

    assert.equal(wire.selectedConversationId, null);
    assert.equal(conversationIdFromHref(history.href), null);
});

test('deep-linked thread inserts a list entry underneath so the first Back stays in Inbox', () => {
    const history = fakeHistory('https://example.test/admin/inbox?conversation=8', { alpine: { snapshotIdx: 2 } });
    const wire = fakeWire(8);
    const api = mobileApi(wire, history);

    api.boot();

    assert.equal(history.urls().length, 2);
    assert.equal(conversationIdFromHref(history.urls()[0]), null);
    assert.equal(conversationIdFromHref(history.urls()[1]), 8);
    assert.equal(history.state.inboxPane, 'thread');

    history.back();
    api.onPopState();

    assert.equal(wire.selectedConversationId, null);
    assert.equal(conversationIdFromHref(history.href), null);
});

test('late boot while a thread is already open still parks the list underneath', () => {
    const history = fakeHistory('https://example.test/admin/dashboard');
    history.pushState({}, '', 'https://example.test/admin/inbox?conversation=4292');

    const wire = fakeWire(4292);
    const api = mobileApi(wire, history);

    api.boot();

    assert.equal(history.urls().length, 3);
    assert.match(history.urls()[0], /\/admin\/dashboard$/);
    assert.equal(conversationIdFromHref(history.urls()[1]), null);
    assert.equal(conversationIdFromHref(history.urls()[2]), 4292);

    history.back();
    api.onPopState();

    assert.equal(wire.mobileThreadOpen, false);
    assert.match(history.href, /\/admin\/inbox$/);
    assert.equal(conversationIdFromHref(history.href), null);
});

test('list then A then Back then B then Back returns to the list, not A', () => {
    const history = fakeHistory('https://example.test/admin/dashboard');
    history.pushState({ alpine: { snapshotIdx: 'inbox' } }, '', 'https://example.test/admin/inbox');

    const wire = fakeWire(null);
    const api = mobileApi(wire, history);

    api.prepare(false, 1);
    wire.set('selectedConversationId', 1);

    history.back();
    api.onPopState();

    api.prepare(false, 2);
    wire.set('selectedConversationId', 2);

    history.back();
    api.onPopState();

    assert.equal(wire.selectedConversationId, null);
    assert.equal(wire.mobileThreadOpen, false);
    assert.equal(conversationIdFromHref(history.href), null);
    assert.match(history.href, /\/admin\/inbox$/);
    assert.match(history.urls()[0], /\/admin\/dashboard$/);
});

test('same-document inbox Back is not a wire:navigate restore', () => {
    assert.equal(
        isSameInboxDocument('https://example.test/admin/inbox?conversation=2', 'https://example.test/admin/inbox'),
        true,
    );
    assert.equal(
        isSameInboxDocument('https://example.test/admin/inbox', 'https://example.test/admin/dashboard'),
        false,
    );

    let prevented = false;
    const event = {
        detail: { url: 'https://example.test/admin/inbox' },
        preventDefault() {
            prevented = true;
        },
    };

    assert.equal(
        preventSameDocumentInboxNavigate(event, 'https://example.test/admin/inbox?conversation=2'),
        true,
    );
    assert.equal(prevented, true);

    prevented = false;
    assert.equal(
        preventSameDocumentInboxNavigate(
            { detail: { url: 'https://example.test/admin/dashboard' }, preventDefault() { prevented = true; } },
            'https://example.test/admin/inbox',
        ),
        false,
    );
    assert.equal(prevented, false);
});

test('desktop Back still restores the conversation from the URL', () => {
    const history = fakeHistory('https://example.test/admin/inbox?conversation=3');
    const wire = fakeWire(null);
    const api = createInboxThreadHistory(wire, {
        history,
        getHref: () => history.href,
        isMobile: () => false,
    });

    api.onPopState();

    assert.equal(wire.selectedConversationId, 3);
    assert.equal(wire.mobileThreadOpen, true);
});

test('conversation click still selects when the prepare helper is missing', () => {
    const previous = globalThis.__inboxPrepareThreadHistory;
    delete globalThis.__inboxPrepareThreadHistory;

    let selected = null;
    const $wire = {
        mobileThreadOpen: false,
        selectConversation(id) {
            selected = id;
        },
    };

    typeof globalThis.__inboxPrepareThreadHistory === 'function' && globalThis.__inboxPrepareThreadHistory($wire.mobileThreadOpen, 4576);
    $wire.selectConversation(4576);

    assert.equal(selected, 4576);

    if (previous !== undefined) {
        globalThis.__inboxPrepareThreadHistory = previous;
    } else {
        delete globalThis.__inboxPrepareThreadHistory;
    }
});
