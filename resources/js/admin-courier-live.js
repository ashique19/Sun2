/**
 * Live courier status for admin order lists / show.
 *
 * Packzy/Steadfast is fetched here, outside Livewire, so a slow or down courier
 * API cannot freeze the page. Results are written back with $wire.apply…;
 * failures call $wire.settle… so the chip spinner always stops.
 */

const BATCH_SIZE = 2;
const CONCURRENCY = 2;
const FETCH_TIMEOUT_MS = 20000;

const inflightIds = new Set();
let currentAbort = null;

const csrfToken = () => (typeof document === 'undefined'
    ? ''
    : document.querySelector('meta[name="csrf-token"]')?.content ?? '');

const wireFor = (el) => {
    const id = el?.closest?.('[wire\\:id]')?.getAttribute('wire:id');

    return id && window.Livewire ? window.Livewire.find(id) : null;
};

const parseIds = (raw) => {
    if (Array.isArray(raw)) {
        return raw.map((id) => Number(id)).filter((id) => Number.isInteger(id) && id > 0);
    }

    if (typeof raw !== 'string' || raw.trim() === '') {
        return [];
    }

    try {
        return parseIds(JSON.parse(raw));
    } catch {
        return [];
    }
};

const chunk = (items, size) => {
    const groups = [];

    for (let i = 0; i < items.length; i += size) {
        groups.push(items.slice(i, i + size));
    }

    return groups;
};

const postBatch = async (url, orderIds, signal) => {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
    const onAbort = () => controller.abort();

    signal?.addEventListener('abort', onAbort);

    try {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ order_ids: orderIds }),
        });

        if (! response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const body = await response.json().catch(() => ({}));

        return body?.statuses && typeof body.statuses === 'object' ? body.statuses : {};
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', onAbort);
    }
};

const runBatches = async (url, orderIds, wire, signal) => {
    const groups = chunk(orderIds, BATCH_SIZE);
    let cursor = 0;

    const worker = async () => {
        while (cursor < groups.length && ! signal?.aborted) {
            const batch = groups[cursor];
            cursor += 1;

            try {
                const statuses = await postBatch(url, batch, signal);

                if (signal?.aborted || ! wire) {
                    return;
                }

                await wire.applyCourierLiveStatuses(statuses);
            } catch {
                if (signal?.aborted || ! wire) {
                    return;
                }

                try {
                    await wire.settleCourierLiveStatuses(batch);
                } catch {
                    // Spinner stays until the next successful poll.
                }
            } finally {
                batch.forEach((id) => inflightIds.delete(id));
            }
        }
    };

    const workers = Array.from({ length: Math.min(CONCURRENCY, groups.length) }, () => worker());

    await Promise.all(workers);
};

const boot = () => {
    if (typeof document === 'undefined') {
        return;
    }

    document.querySelectorAll('[data-admin-courier-live-root]').forEach((el) => {
        const url = el.dataset.adminCourierLiveUrl || '';
        const pending = parseIds(el.dataset.adminCourierLivePendingIds);
        const toFetch = pending.filter((id) => ! inflightIds.has(id));

        if (! url || toFetch.length === 0) {
            return;
        }

        const overlap = pending.some((id) => inflightIds.has(id));

        if (! overlap && currentAbort) {
            currentAbort.abort();
            currentAbort = null;
        }

        const wire = wireFor(el);

        if (! wire) {
            return;
        }

        toFetch.forEach((id) => inflightIds.add(id));

        const abort = new AbortController();

        currentAbort = abort;
        runBatches(url, toFetch, wire, abort.signal).finally(() => {
            if (currentAbort === abort) {
                currentAbort = null;
            }
        });
    });
};

const scheduleBoot = () => {
    queueMicrotask(boot);
};

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleBoot);
    } else {
        scheduleBoot();
    }

    document.addEventListener('livewire:navigated', scheduleBoot);
    document.addEventListener('livewire:init', () => {
        if (! window.Livewire?.hook) {
            return;
        }

        window.Livewire.hook('morph.updated', scheduleBoot);
        window.Livewire.hook('commit', ({ succeed }) => {
            succeed(scheduleBoot);
        });
    });
}

export { boot };
