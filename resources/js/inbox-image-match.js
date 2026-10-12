// Inbox photo → product matching runs here, outside Livewire: each pending photo is
// a POST to admin.inbox.image-match, a few in parallel, and the inbox re-reads the
// persisted results (debounced) so chips appear progressively while staff keep working.
//
// Every request has a hard timeout and bounded retries, and every spinner has a watchdog,
// so "Matching product…" always ends in a result or a visible error with Retry.

const queue = [];
const jobs = new Map();
// Per element, not on the Alpine object. Nested x-data writes unknown properties
// through Alpine's scope proxy onto the outermost component, so a flag stored on
// `data` made every photo after the first look already started and never match.
const started = new WeakSet();
let active = 0;
let refreshTimer = null;

const meta = (name, fallback) => {
    if (typeof document === 'undefined') {
        return fallback;
    }

    const value = Number(document.querySelector(`meta[name="${name}"]`)?.content);

    return Number.isFinite(value) && value > 0 ? value : fallback;
};

const concurrency = () => Math.max(1, meta('inbox-image-match-concurrency', 3));
const timeoutMs = () => meta('inbox-image-match-timeout-ms', 30000);
const retries = () => Math.max(0, Math.round(meta('inbox-image-match-retries', 2)));

const csrfToken = () => (typeof document === 'undefined'
    ? ''
    : document.querySelector('meta[name="csrf-token"]')?.content ?? '');

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const wireFor = (el) => {
    const id = el?.closest?.('[wire\\:id]')?.getAttribute('wire:id');

    return id && window.Livewire ? window.Livewire.find(id) : null;
};

const scheduleRefresh = (wire) => {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
        wire?.refreshInboundImageMatches?.();
    }, 150);
};

class RetryableError extends Error {}

const postOnce = async (url) => {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs());

    try {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (response.status === 202) {
            throw new RetryableError('Another request is still matching this photo.');
        }

        if (response.status === 419) {
            throw new Error('Your session expired. Reload the page and retry.');
        }

        if (response.status === 403 || response.status === 404) {
            throw new Error(`Matching is unavailable for this photo (HTTP ${response.status}).`);
        }

        if (! response.ok) {
            throw new RetryableError(`Server error (HTTP ${response.status}).`);
        }

        return await response.json().catch(() => ({}));
    } catch (error) {
        if (error?.name === 'AbortError') {
            throw new RetryableError(`No answer from the server after ${Math.round(timeoutMs() / 1000)}s.`);
        }

        if (error instanceof TypeError) {
            throw new RetryableError('Network error while matching.');
        }

        throw error;
    } finally {
        clearTimeout(timer);
    }
};

const run = async (url) => {
    const attempts = retries() + 1;
    let lastError = null;

    for (let attempt = 1; attempt <= attempts; attempt++) {
        try {
            const body = await postOnce(url);

            return { ok: ! body?.error, error: body?.error ?? null };
        } catch (error) {
            lastError = error;
            if (! (error instanceof RetryableError) || attempt === attempts) {
                break;
            }
            await sleep(1500 * attempt);
        }
    }

    return { ok: false, error: lastError?.message || 'Couldn’t match this photo.' };
};

const pump = () => {
    while (active < concurrency() && queue.length > 0) {
        const job = queue.shift();
        active++;

        run(job.url)
            .then(job.resolve)
            .finally(() => {
                active--;
                pump();
            });
    }
};

/** One request per URL per page; later spinners for the same URL share its result. */
const match = (url) => {
    if (! jobs.has(url)) {
        jobs.set(url, new Promise((resolve) => {
            queue.push({ url, resolve });
            pump();
        }));
    }

    return jobs.get(url);
};

const watchdogMs = () => (timeoutMs() + 2000) * (retries() + 1) + 15000;

const start = (data, el) => {
    const url = el?.dataset?.inboxImageMatchUrl || data?.url || '';
    if (! data || ! el || started.has(el) || url === '') {
        return;
    }
    started.add(el);

    const fail = (message) => {
        data.state = 'error';
        data.error = message;
    };

    // The server replaces this element once the result is saved; if it never does, say so.
    const watchdog = setTimeout(() => fail('Matching is taking too long. Retry, or search the product.'), watchdogMs());
    watchdog.unref?.();

    match(url).then((result) => {
        if (! el.isConnected) {
            clearTimeout(watchdog);

            return;
        }

        if (! result.ok) {
            clearTimeout(watchdog);
            fail(result.error);
        }

        scheduleRefresh(wireFor(el));
    });
};

globalThis.inboxImageMatchPending = (url) => ({
    url,
    state: 'loading',
    error: '',

    init() {
        start(this, this.$el);
    },
});

globalThis.inboxImageMatchRefresh = (url) => ({
    init() {
        const el = this.$el;
        match(url).then(() => {
            if (el.isConnected) {
                scheduleRefresh(wireFor(el));
            }
        });
    },
});

// Spinners rendered before this script ran (e.g. after wire:navigate) used the inline
// fallback data; adopt them so their requests still go out.
const adoptRendered = () => {
    if (! window.Alpine) {
        return;
    }

    document.querySelectorAll('[data-inbox-image-match-url]').forEach((el) => {
        const data = el._x_dataStack ? window.Alpine.$data(el) : null;
        if (data) {
            start(data, el);
        }
    });
};

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => setTimeout(adoptRendered, 0));
    } else {
        setTimeout(adoptRendered, 0);
    }
    document.addEventListener('livewire:navigated', () => setTimeout(adoptRendered, 0));
}

export { start };
