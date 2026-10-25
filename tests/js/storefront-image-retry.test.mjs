import assert from 'node:assert/strict';
import test from 'node:test';
import {
    MAX_AUTO_RETRIES,
    imageNeedsRecover,
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
        visibilityState: 'visible',
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

function fakeWindow() {
    const listeners = {};

    return {
        listeners,
        addEventListener(type, fn) {
            listeners[type] = listeners[type] ?? [];
            listeners[type].push(fn);
        },
        dispatch(type) {
            (listeners[type] ?? []).forEach((fn) => fn({}));
        },
        navigator: { onLine: true },
        IntersectionObserver: undefined,
    };
}

function fakeImage(src) {
    const wrap = {
        failed: false,
        loading: true,
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
        closest(selector) {
            return selector === '[data-image-retry-wrap]' ? this : selector === '[data-image-retry-btn]' ? null : null;
        },
        button: {
            hidden: true,
            closest(selector) {
                if (selector === '[data-image-retry-btn]') {
                    return this;
                }

                return selector === '[data-image-retry-wrap]' ? wrap : null;
            },
        },
    };

    const img = {
        tagName: 'IMG',
        src,
        srcset: 'a.jpg 200w',
        currentSrc: src,
        complete: false,
        naturalWidth: 0,
        dataset: { retryAttempt: '0' },
        wrap,
        hasAttribute: (name) => name === 'data-image-retry',
        getAttribute: (name) => (name === 'data-image-src' ? src : name === 'src' ? img.src : null),
        removeAttribute(name) {
            if (name === 'srcset' || name === 'sizes') {
                img.srcset = '';
            }
        },
        closest(selector) {
            return selector === '[data-image-retry-wrap]' ? wrap : selector === '[data-image-retry-btn]' ? null : wrap;
        },
    };

    return img;
}

function start(doc, extra = {}) {
    const win = extra.window ?? fakeWindow();
    const scheduled = extra.scheduled ?? [];

    const api = startImageRetry({
        document: doc,
        window: win,
        now: extra.now ?? (() => 99),
        schedule: extra.schedule ?? ((fn) => {
            scheduled.push(fn);

            return scheduled.length;
        }),
        online: extra.online ?? (() => true),
        ...extra.startOptions,
    });

    return { api, win, scheduled };
}

test('error queues an auto retry that drops srcset and cache-busts src', () => {
    const { doc, images } = fakeDocument();
    const img = fakeImage('/img/nupur.jpg');
    images.push(img);
    const { scheduled } = start(doc);

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

    start(doc, { schedule: (fn) => fn() });

    doc.dispatch('error', img);
    assert.equal(img.wrap.failed, true);
    assert.equal(img.wrap.button.hidden, false);
    assert.equal(img.wrap.loading, false);
});

test('offline failures keep the spinner instead of swapping to the tap control', () => {
    const { doc, images } = fakeDocument();
    const img = fakeImage('/img/nupur.jpg');
    images.push(img);
    let scheduled = 0;

    start(doc, {
        schedule: (fn) => {
            scheduled += 1;

            return fn;
        },
        online: () => false,
    });

    doc.dispatch('error', img);
    assert.equal(scheduled, 0);
    assert.equal(img.wrap.button.hidden, true);
    assert.equal(img.wrap.loading, true);
    assert.equal(img.wrap.failed, true);
});

test('network restore restarts failed images and brings the spinner back', () => {
    const { doc, images } = fakeDocument();
    const img = fakeImage('/img/nupur.jpg');
    img.complete = true;
    img.naturalWidth = 0;
    img.dataset.retryAttempt = String(MAX_AUTO_RETRIES);
    img.wrap.failed = true;
    img.wrap.loading = false;
    img.wrap.button.hidden = false;
    images.push(img);

    const { win } = start(doc, { now: () => 55 });

    win.dispatch('online');

    assert.equal(img.dataset.retryAttempt, '0');
    assert.equal(img.src, '/img/nupur.jpg?retry=55');
    assert.equal(img.wrap.loading, true);
    assert.equal(img.wrap.failed, false);
    assert.equal(img.wrap.button.hidden, true);
});

test('network restore restarts hung in-flight images that never fired error', () => {
    const { doc, images } = fakeDocument();
    const img = fakeImage('/img/nupur.jpg');
    img.complete = false;
    img.naturalWidth = 0;
    img.wrap.failed = false;
    img.wrap.loading = true;
    images.push(img);

    const { win } = start(doc, { now: () => 77 });

    win.dispatch('online');

    assert.equal(img.src, '/img/nupur.jpg?retry=77');
    assert.equal(img.wrap.loading, true);
    assert.equal(img.wrap.button.hidden, true);
});

test('a successful image load recovers other failed images on the page', () => {
    const { doc, images } = fakeDocument();
    const loaded = fakeImage('/img/ok.jpg');
    const failed = fakeImage('/img/bad.jpg');
    failed.complete = true;
    failed.naturalWidth = 0;
    failed.wrap.failed = true;
    failed.wrap.loading = false;
    failed.wrap.button.hidden = false;
    images.push(loaded, failed);

    start(doc, { now: () => 12 });

    loaded.naturalWidth = 400;
    loaded.complete = true;
    doc.dispatch('load', loaded);

    assert.equal(failed.src, '/img/bad.jpg?retry=12');
    assert.equal(failed.wrap.loading, true);
    assert.equal(failed.wrap.button.hidden, true);
});

test('clicking a failed image or its wrap retries and blocks the product link', () => {
    const { doc, images } = fakeDocument();
    const img = fakeImage('/img/nupur.jpg');
    img.complete = true;
    img.naturalWidth = 0;
    img.dataset.retryAttempt = String(MAX_AUTO_RETRIES);
    img.wrap.failed = true;
    img.wrap.loading = false;
    img.wrap.button.hidden = false;
    images.push(img);

    start(doc, { now: () => 42 });

    let prevented = 0;
    const click = {
        target: img,
        preventDefault() {
            prevented += 1;
        },
        stopPropagation() {
            prevented += 1;
        },
    };

    doc.listeners.click.forEach(({ fn }) => fn(click));

    assert.equal(prevented, 2);
    assert.equal(img.dataset.retryAttempt, '0');
    assert.equal(img.src, '/img/nupur.jpg?retry=42');
    assert.equal(img.wrap.loading, true);
    assert.equal(img.wrap.button.hidden, true);
});

test('clicking a loaded listing image does not steal the product link', () => {
    const { doc, images } = fakeDocument();
    const img = fakeImage('/img/nupur.jpg');
    img.complete = true;
    img.naturalWidth = 400;
    img.wrap.failed = false;
    img.wrap.loading = false;
    images.push(img);

    start(doc, { now: () => 1 });
    const srcBefore = img.src;
    let prevented = 0;

    doc.listeners.click.forEach(({ fn }) => fn({
        target: img,
        preventDefault() {
            prevented += 1;
        },
        stopPropagation() {
            prevented += 1;
        },
    }));

    assert.equal(prevented, 0);
    assert.equal(img.src, srcBefore);
});

test('imageNeedsRecover treats failed and broken images as waiting', () => {
    const failed = fakeImage('/img/nupur.jpg');
    failed.wrap.failed = true;
    failed.complete = true;
    failed.naturalWidth = 0;
    assert.equal(imageNeedsRecover(failed), true);

    const hung = fakeImage('/img/nupur.jpg');
    hung.complete = false;
    hung.naturalWidth = 0;
    assert.equal(imageNeedsRecover(hung), false);
    assert.equal(imageNeedsRecover(hung, { includeHung: true }), true);

    const ok = fakeImage('/img/nupur.jpg');
    ok.complete = true;
    ok.naturalWidth = 400;
    assert.equal(imageNeedsRecover(ok, { includeHung: true }), false);
});
