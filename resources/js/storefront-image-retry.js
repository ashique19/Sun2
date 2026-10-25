export const MAX_AUTO_RETRIES = 3;
export const RETRY_DELAYS_MS = [800, 2000, 4500];
export const RESTORE_FOLLOWUP_MS = 800;

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

export function imageHasPixels(img) {
    return Boolean(img && (img.naturalWidth || 0) > 0);
}

/**
 * Failed, broken, or (optionally) hung in-flight images that should be restarted.
 */
export function imageNeedsRecover(img, { includeHung = false } = {}) {
    if (! isRetryImage(img) || imageHasPixels(img)) {
        return false;
    }

    const wrap = wrapOf(img);

    if (wrap?.hasAttribute('data-image-failed')) {
        return true;
    }

    if (img.complete) {
        return true;
    }

    return includeHung;
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

function showLoading(img) {
    wrapOf(img)?.setAttribute('data-image-loading', '');
}

function hideLoading(img) {
    wrapOf(img)?.removeAttribute('data-image-loading');
}

function showRetryUi(img) {
    const wrap = wrapOf(img);
    const button = retryButton(img);

    hideLoading(img);

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

function markWaiting(img) {
    const wrap = wrapOf(img);
    const button = retryButton(img);

    wrap?.setAttribute('data-image-failed', '');
    showLoading(img);

    if (button) {
        button.hidden = true;
    }
}

function currentSrcValue(img) {
    return String(img.getAttribute('src') || img.currentSrc || img.src || '');
}

function applyRetry(img, token) {
    const src = originalSrc(img);

    if (! src) {
        return false;
    }

    hideRetryUi(img);
    showLoading(img);
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
            markWaiting(img);

            return;
        }

        const attempt = attemptOf(img);

        if (attempt >= MAX_AUTO_RETRIES) {
            showRetryUi(img);

            return;
        }

        showLoading(img);
        hideRetryUi(img);

        const handle = schedule(() => {
            pending.delete(img);
            img.dataset.retryAttempt = String(attempt + 1);
            applyRetry(img, now());
        }, nextRetryDelay(attempt));

        pending.set(img, handle);
    };

    const recoverWaitingImages = ({ reset = true, includeHung = true } = {}) => {
        if (! online()) {
            return;
        }

        doc.querySelectorAll('[data-image-retry]').forEach((img) => {
            if (imageNeedsRecover(img, { includeHung })) {
                retryNow(img, { reset });
            }
        });
    };

    const retryVisibleFailed = () => recoverWaitingImages({ reset: true, includeHung: true });

    const recoverAfterRestore = () => {
        recoverWaitingImages({ reset: true, includeHung: true });
        schedule(() => recoverWaitingImages({ reset: true, includeHung: true }), RESTORE_FOLLOWUP_MS);
    };

    const markUnloadedWaiting = () => {
        doc.querySelectorAll('[data-image-retry]').forEach((img) => {
            if (! isRetryImage(img) || imageHasPixels(img)) {
                return;
            }

            clearPending(img);
            markWaiting(img);
        });
    };

    doc.addEventListener('error', (event) => {
        const img = event.target;

        if (! isRetryImage(img) || ! currentSrcValue(img)) {
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
        hideLoading(img);

        if (online()) {
            recoverWaitingImages({ reset: true, includeHung: false });
        }
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

    win.addEventListener('online', recoverAfterRestore);
    win.addEventListener('offline', markUnloadedWaiting);
    win.addEventListener('pageshow', () => {
        if (online()) {
            recoverAfterRestore();
        }
    });
    win.addEventListener('focus', () => {
        if (online()) {
            recoverWaitingImages({ reset: true, includeHung: true });
        }
    });

    doc.addEventListener('visibilitychange', () => {
        if ((doc.visibilityState ?? 'visible') === 'visible' && online()) {
            recoverWaitingImages({ reset: true, includeHung: true });
        }
    });

    const connection = win.navigator?.connection;

    if (connection && typeof connection.addEventListener === 'function') {
        connection.addEventListener('change', () => {
            if (online()) {
                recoverWaitingImages({ reset: true, includeHung: true });
            }
        });
    }

    if (typeof win.IntersectionObserver === 'function') {
        const observer = new win.IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (! entry.isIntersecting || ! online()) {
                    return;
                }

                const img = entry.target.querySelector?.('[data-image-retry]') ?? entry.target;

                if (imageNeedsRecover(img, { includeHung: true })) {
                    retryNow(img, { reset: true });
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

    doc.querySelectorAll('[data-image-retry]').forEach((img) => {
        if (! isRetryImage(img) || ! img.complete) {
            return;
        }

        if (imageHasPixels(img)) {
            hideRetryUi(img);
            hideLoading(img);
        } else if (online()) {
            queueRetry(img);
        } else {
            markWaiting(img);
        }
    });

    return { retryNow, queueRetry, retryVisibleFailed, recoverWaitingImages };
}
