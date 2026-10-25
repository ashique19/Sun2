import assert from 'node:assert/strict';
import test from 'node:test';
import {
    MAX_AUTO_RETRIES,
    nextRetryDelay,
    startImageRetry,
    stripRetryQuery,
    withRetryQuery,
} from '../../resources/js/storefront-image-retry.js';

test('withRetryQuery stamps a cache-buster and stripRetryQuery removes it', () => {
    assert.equal(withRetryQuery('/img/nupur.jpg', 10), '/img/nupur.jpg?retry=10');
    assert.equal(withRetryQuery('/img/nupur.jpg?v=1', 11), '/img/nupur.jpg?v=1&retry=11');
    assert.equal(stripRetryQuery('/img/nupur.jpg?retry=11'), '/img/nupur.jpg');
    assert.equal(stripRetryQuery('/img/nupur.jpg?v=1&retry=11'), '/img/nupur.jpg?v=1');
    assert.equal(withRetryQuery('data:image/gif;base64,xx', 1), 'data:image/gif;base64,xx');
});

test('nextRetryDelay grows then caps', () => {
    assert.equal(nextRetryDelay(0), 800);
    assert.equal(nextRetryDelay(1), 2000);
    assert.equal(nextRetryDelay(2), 4500);
    assert.equal(nextRetryDelay(9), 4500);
});

function fakeDocument() {
    const listeners = {};
    const images = [];

    const doc = {
        listeners,
        addEventListener(type, fn, opts) {
            listeners[type] = listeners[type] ?? [];
            listeners[type].push({ fn, opts });
        },
        querySelectorAll(selector) {
            if (selector.includes('data-image-failed')) {
                return images.filter((img) => img.wrap?.failed);
            }

            return images;
        },
        documentElement: { nodeType: 1 },
        dispatch(type, target) {
            (listeners[type] ?? []).forEach(({ fn }) => fn({
                target,
                preventDefault() {},
                stopPropagation() {},
            }));
        },
    };

    return { doc, images };
}

function fakeImage(src) {
    const wrap = {
        failed: false,
        loading: false,
        setAttribute(name) {
            if (name === 'data-image-failed') {
                this.failed = true;
            }

            if (name === 'data-image-loading') {
                this.loading = true;
            }
        },
        removeAttribute(name) {
            if (name === 'data-image-failed') {
                this.failed = false;
            }

            if (name === 'data-image-loading') {
                this.loading = false;
            }
        },
        hasAttribute(name) {
            return name === 'data-image-failed' ? this.failed : name === 'data-image-loading' ? this.loading : false;
        },
        querySelector(selector) {
            return selector === '[data-image-retry-btn]' ? this.button : selector === '[data-image-retry]' ? img : null;
        },
        button: { hidden: true },
    };

    const img = {
        tagName: 'IMG',
        src,
        srcset: 'a.jpg 200w',
        dataset: { retryAttempt: '0' },
        wrap,
        hasAttribute: (name) => name === 'data-image-retry',
        getAttribute: (name) => (name === 'data-image-src' ? src : name === 'src' ? img.src : null),
        removeAttribute(name) {
            if (name === 'srcset' || name === 'sizes') {
                img.srcset = '';
            }
        },
        closest: () => wrap,
    };

    return img;
}

test('error queues an auto retry that drops srcset and cache-busts src', () => {
    const { doc, images } = fakeDocument();
    const scheduled = [];
    const img = fakeImage('/img/nupur.jpg');
    images.push(img);

    startImageRetry({
        document: doc,
        window: {
            addEventListener() {},
            navigator: { onLine: true },
            IntersectionObserver: undefined,
        },
        now: () => 99,
        schedule: (fn) => {
            scheduled.push(fn);

            return scheduled.length;
        },
        online: () => true,
    });

    doc.dispatch('error', img);
    assert.equal(scheduled.length, 1);
    scheduled[0]();

    assert.equal(img.dataset.retryAttempt, '1');
    assert.equal(img.srcset, '');
    assert.equal(img.src, '/img/nupur.jpg?retry=99');
    assert.equal(img.wrap.button.hidden, true);
    assert.equal(img.wrap.loading, true);
});

test('after max auto retries the tap-to-reload control is shown', () => {
    const { doc, images } = fakeDocument();
    const img = fakeImage('/img/nupur.jpg');
    img.dataset.retryAttempt = String(MAX_AUTO_RETRIES);
    images.push(img);

    startImageRetry({
        document: doc,
        window: {
            addEventListener() {},
            navigator: { onLine: true },
            IntersectionObserver: undefined,
        },
        now: () => 1,
        schedule: (fn) => fn(),
        online: () => true,
    });

    doc.dispatch('error', img);
    assert.equal(img.wrap.failed, true);
    assert.equal(img.wrap.button.hidden, false);
});

test('offline failures show the retry control instead of hammering', () => {
    const { doc, images } = fakeDocument();
    const img = fakeImage('/img/nupur.jpg');
    images.push(img);
    let scheduled = 0;

    startImageRetry({
        document: doc,
        window: {
            addEventListener() {},
            navigator: { onLine: false },
            IntersectionObserver: undefined,
        },
        now: () => 1,
        schedule: (fn) => {
            scheduled += 1;

            return fn;
        },
        online: () => false,
    });

    doc.dispatch('error', img);
    assert.equal(scheduled, 0);
    assert.equal(img.wrap.button.hidden, false);
});
