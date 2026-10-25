export const MAX_AUTO_RETRIES = 3;
export const RETRY_DELAYS_MS = [800, 2000, 4500];

/**
 * Drop a previous retry query so we can stamp a fresh one.
 */
export function stripRetryQuery(url) {
    if (! url) {
        return '';
    }

    const hashIndex = url.indexOf('#');
    const hash = hashIndex === -1 ? '' : url.slice(hashIndex);
    const withoutHash = hashIndex === -1 ? url : url.slice(0, hashIndex);
    const cleaned = withoutHash
        .replace(/([?&])retry=\d+/g, '$1')
        .replace(/[?&]+$/g, '')
        .replace(/\?&/, '?')
        .replace(/&&+/g, '&');

    return cleaned + hash;
}

export function withRetryQuery(url, token) {
    const cleaned = stripRetryQuery(url);

    if (! cleaned || cleaned.startsWith('data:') || cleaned.startsWith('blob:')) {
        return cleaned;
    }

    const hashIndex = cleaned.indexOf('#');
    const hash = hashIndex === -1 ? '' : cleaned.slice(hashIndex);
    const base = hashIndex === -1 ? cleaned : cleaned.slice(0, hashIndex);
    const sep = base.includes('?') ? '&' : '?';

    return `${base}${sep}retry=${token}${hash}`;
}

export function nextRetryDelay(attempt) {
    return RETRY_DELAYS_MS[Math.min(Math.max(0, attempt), RETRY_DELAYS_MS.length - 1)];
}

function isRetryImage(node) {
    if (! node || typeof node.hasAttribute !== 'function') {
        return false;
    }

    const name = String(node.tagName || node.nodeName || '').toUpperCase();

    return name === 'IMG' && node.hasAttribute('data-image-retry');
}

function originalSrc(img) {
    return img.getAttribute('data-image-src') || stripRetryQuery(img.currentSrc || img.getAttribute('src') || '');
}

function wrapOf(img) {
    return img.closest('[data-image-retry-wrap]');
}

function retryButton(img) {
    return wrapOf(img)?.querySelector('[data-image-retry-btn]') ?? null;
}

function showRetryUi(img) {
    const wrap = wrapOf(img);
    const button = retryButton(img);

    if (wrap) {
        wrap.setAttribute('data-image-failed', '');
    }

    if (button) {
        button.hidden = false;
    }
}

function hideRetryUi(img) {
    const wrap = wrapOf(img);
    const button = retryButton(img);

    wrap?.removeAttribute('data-image-failed');

    if (button) {
        button.hidden = true;
    }
}

function applyRetry(img, token) {
    const src = originalSrc(img);

    if (! src) {
        return false;
    }

    hideRetryUi(img);
    img.removeAttribute('srcset');
    img.removeAttribute('sizes');
    img.src = withRetryQuery(src, token);

    return true;
}

export function startImageRetry(options = {}) {
    const doc = options.document ?? document;
    const win = options.window ?? window;
    const now = options.now ?? (() => Date.now());
    const schedule = options.schedule ?? ((fn, ms) => win.setTimeout(fn, ms));
    const online = () => (typeof options.online === 'function' ? options.online() : win.navigator.onLine !== false);

    const pending = new WeakMap();

    const clearPending = (img) => {
        const handle = pending.get(img);

        if (handle != null && typeof options.cancel === 'function') {
            options.cancel(handle);
        }

        pending.delete(img);
    };

    const attemptOf = (img) => Number(img.dataset.retryAttempt || 0);

    const retryNow = (img, { reset = false } = {}) => {
        clearPending(img);

        if (reset) {
            img.dataset.retryAttempt = '0';
        }

        return applyRetry(img, now());
    };

    const queueRetry = (img) => {
        if (pending.has(img)) {
            return;
        }

        if (! online()) {
            showRetryUi(img);

            return;
        }

        const attempt = attemptOf(img);

        if (attempt >= MAX_AUTO_RETRIES) {
            showRetryUi(img);

            return;
        }

        const handle = schedule(() => {
            pending.delete(img);
            img.dataset.retryAttempt = String(attempt + 1);
            applyRetry(img, now());
        }, nextRetryDelay(attempt));

        pending.set(img, handle);
    };

    const retryVisibleFailed = () => {
        doc.querySelectorAll('[data-image-retry-wrap][data-image-failed] [data-image-retry]').forEach((img) => {
            if (isRetryImage(img)) {
                retryNow(img);
            }
        });
    };

    doc.addEventListener('error', (event) => {
        const img = event.target;

        if (! isRetryImage(img)) {
            return;
        }

        queueRetry(img);
    }, true);

    doc.addEventListener('load', (event) => {
        const img = event.target;

        if (! isRetryImage(img)) {
            return;
        }

        clearPending(img);
        img.dataset.retryAttempt = '0';
        hideRetryUi(img);
    }, true);

    doc.addEventListener('click', (event) => {
        const target = event.target;
        const button = target && typeof target.closest === 'function'
            ? target.closest('[data-image-retry-btn]')
            : null;

        if (! button) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const img = button.closest('[data-image-retry-wrap]')?.querySelector('[data-image-retry]');

        if (isRetryImage(img)) {
            retryNow(img, { reset: true });
        }
    }, true);

    win.addEventListener('online', retryVisibleFailed);

    if (typeof win.IntersectionObserver === 'function') {
        const observer = new win.IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (! entry.isIntersecting) {
                    return;
                }

                const img = entry.target.querySelector?.('[data-image-retry]') ?? entry.target;

                if (isRetryImage(img) && wrapOf(img)?.hasAttribute('data-image-failed')) {
                    retryNow(img);
                }
            });
        }, { rootMargin: '80px 0px' });

        const watch = (root) => {
            root.querySelectorAll?.('[data-image-retry-wrap]').forEach((wrap) => observer.observe(wrap));
        };

        watch(doc);
        const mutations = new MutationObserver((records) => {
            records.forEach((record) => {
                record.addedNodes.forEach((node) => {
                    if (node instanceof Element) {
                        if (node.matches('[data-image-retry-wrap]')) {
                            observer.observe(node);
                        }

                        watch(node);
                    }
                });
            });
        });

        mutations.observe(doc.documentElement ?? doc, { childList: true, subtree: true });
    }

    return { retryNow, queueRetry, retryVisibleFailed };
}
