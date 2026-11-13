import assert from 'node:assert/strict';
import test from 'node:test';
import {
    AXIS_LOCK,
    EDGE_GUARD,
    MAX_PULL,
    REPLY_THRESHOLD,
    clampedPull,
    replyDirection,
    shouldCommitReply,
    swipeAxis,
} from '../../resources/js/inbox-swipe-reply.js';

test('inbound reply is a right swipe; outbound is a left swipe', () => {
    assert.equal(replyDirection(false), 1);
    assert.equal(replyDirection(true), -1);
});

test('axis lock prefers the larger movement', () => {
    assert.equal(swipeAxis(0, 0), null);
    assert.equal(swipeAxis(AXIS_LOCK - 1, 0), null);
    assert.equal(swipeAxis(40, 8), 'horizontal');
    assert.equal(swipeAxis(8, 40), 'vertical');
});

test('wrong-way swipes do not pull', () => {
    assert.equal(clampedPull(-40, 1), 0);
    assert.equal(clampedPull(40, -1), 0);
});

test('pull follows the reply direction up to the max, then rubber-bands', () => {
    assert.equal(clampedPull(40, 1), 40);
    assert.equal(clampedPull(-40, -1), 40);
    assert.ok(clampedPull(MAX_PULL + 40, 1) > MAX_PULL);
    assert.ok(clampedPull(MAX_PULL + 40, 1) < MAX_PULL + 40);
});

test('reply commits once the Messenger-style threshold is crossed', () => {
    assert.equal(shouldCommitReply(REPLY_THRESHOLD - 1), false);
    assert.equal(shouldCommitReply(REPLY_THRESHOLD), true);
    assert.ok(EDGE_GUARD > 0);
});
