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
