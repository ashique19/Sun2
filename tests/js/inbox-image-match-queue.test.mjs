import assert from 'node:assert/strict';
import test from 'node:test';
import { start } from '../../resources/js/inbox-image-match.js';

/**
 * Alpine's scope proxy writes unknown properties onto the outermost component.
 * A "started" flag stored on the photo's Alpine data therefore leaked, and every
 * photo after the first skipped its match request.
 */
function alpineScope(own, shared) {
    const objects = [own, shared];

    return new Proxy(own, {
        get(target, name) {
            const found = objects.find((obj) => name in obj);

            return found ? found[name] : undefined;
        },
        set(target, name, value) {
            const found = objects.find((obj) => Object.prototype.hasOwnProperty.call(obj, name));
            (found ?? objects[objects.length - 1])[name] = value;

            return true;
        },
    });
}

test('every pending photo posts its own match url, even when Alpine shares an outer scope', async () => {
    const shared = {};
    const requested = [];
    const originalFetch = globalThis.fetch;

    globalThis.fetch = async (url) => {
        requested.push(String(url));

        return {
            ok: true,
            status: 200,
            json: async () => ({}),
        };
    };

    try {
        const first = {
            dataset: { inboxImageMatchUrl: 'https://shop.test/admin/inbox/media/17/match?attempt=0.0' },
            isConnected: true,
        };
        const second = {
            dataset: { inboxImageMatchUrl: 'https://shop.test/admin/inbox/media/19/match?attempt=0.0' },
            isConnected: true,
        };

        start(alpineScope({ url: first.dataset.inboxImageMatchUrl, state: 'loading', error: '' }, shared), first);
        start(alpineScope({ url: second.dataset.inboxImageMatchUrl, state: 'loading', error: '' }, shared), second);

        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.deepEqual(requested, [
            first.dataset.inboxImageMatchUrl,
            second.dataset.inboxImageMatchUrl,
        ]);
        assert.equal(shared.__inboxMatchStarted, undefined);
    } finally {
        globalThis.fetch = originalFetch;
    }
});
