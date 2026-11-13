import assert from 'node:assert/strict';
import test from 'node:test';
import { conversationIdFromButton, createInboxPendingSend } from '../../resources/js/inbox-pending-send.js';

function fakeHost() {
    return {
        hidden: true,
        attrs: { 'aria-hidden': 'true' },
        setAttribute(name, value) {
            this.attrs[name] = value;
        },
    };
}

test('conversation id prefers the button, then the live thread', () => {
    const button = {
        getAttribute: (name) => (name === 'data-conversation-id' ? '9' : null),
        closest: () => null,
    };

    assert.equal(conversationIdFromButton(button), 9);
    assert.equal(conversationIdFromButton({ getAttribute: () => null, closest: () => null }), 0);
});

test('begin shows dots for the open conversation and end hides them', () => {
    const host = fakeHost();
    const pending = createInboxPendingSend({
        conversationId: () => 4,
        host: () => host,
        scroller: () => ({ scrollTop: 0, scrollHeight: 80 }),
    });

    pending.paint();
    assert.equal(host.hidden, true);

    assert.equal(pending.begin(4), 1);
    assert.equal(host.hidden, false);
    assert.equal(host.attrs['aria-hidden'], 'false');

    assert.equal(pending.begin(4), 2);
    assert.equal(pending.end(4), 1);
    assert.equal(host.hidden, false);

    assert.equal(pending.end(4), 0);
    assert.equal(host.hidden, true);
    assert.equal(host.attrs['aria-hidden'], 'true');
});

test('dots for another conversation do not paint on the open thread', () => {
    const host = fakeHost();
    const pending = createInboxPendingSend({
        conversationId: () => 2,
        host: () => host,
        scroller: () => ({ scrollTop: 0, scrollHeight: 10 }),
    });

    pending.begin(8);
    assert.equal(pending.countFor(8), 1);
    assert.equal(host.hidden, true);

    pending.begin(2);
    assert.equal(host.hidden, false);
});

test('failed send stays visible until retry or dismiss', () => {
    const host = fakeHost();
    const failed = fakeHost();
    const message = { textContent: '' };
    const pending = createInboxPendingSend({
        conversationId: () => 4,
        host: () => host,
        failedHost: () => failed,
        failedMessage: () => message,
        scroller: () => ({ scrollTop: 0, scrollHeight: 80 }),
    });

    pending.begin(4);
    assert.equal(host.hidden, false);
    assert.equal(failed.hidden, true);

    let retried = 0;
    pending.fail(4, 'Graph boom', () => {
        retried += 1;
        pending.begin(4);
    });

    assert.equal(host.hidden, true);
    assert.equal(failed.hidden, false);
    assert.equal(failed.attrs['aria-hidden'], 'false');
    assert.equal(message.textContent, 'Graph boom');
    assert.equal(pending.failedFor(4)?.error, 'Graph boom');

    pending.retry(4);
    assert.equal(retried, 1);
    assert.equal(host.hidden, false);
    assert.equal(failed.hidden, true);
    assert.equal(pending.failedFor(4), null);

    pending.fail(4, 'boom again');
    pending.dismiss(4);
    assert.equal(failed.hidden, true);
    assert.equal(pending.failedFor(4), null);
});

test('failed send retry and dismiss buttons fire from the host', () => {
    const listeners = {};
    const host = fakeHost();
    const failed = fakeHost();
    const message = { textContent: '' };
    const root = {
        addEventListener(type, fn, capture) {
            listeners[type] = { fn, capture };
        },
    };
    const pending = createInboxPendingSend({
        conversationId: () => 3,
        host: () => host,
        failedHost: () => failed,
        failedMessage: () => message,
        scroller: () => ({ scrollTop: 0, scrollHeight: 10 }),
    });

    pending.install(root);
    pending.begin(3);
    let retried = 0;
    pending.fail(3, 'nope', () => {
        retried += 1;
    });

    listeners.click.fn({
        preventDefault() {},
        target: {
            closest: (sel) => (sel === '[data-inbox-failed-send-retry]' ? failed : null),
        },
    });
    assert.equal(retried, 1);
    assert.equal(failed.hidden, true);

    pending.fail(3, 'nope again');
    listeners.click.fn({
        preventDefault() {},
        target: {
            closest: (sel) => (sel === '[data-inbox-failed-send-dismiss]' ? failed : null),
        },
    });
    assert.equal(pending.failedFor(3), null);
    assert.equal(failed.hidden, true);
});
