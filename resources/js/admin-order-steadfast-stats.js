/**
 * Steadfast delivery history on the admin order form.
 *
 * Packzy fraud/score is fetched here, outside Livewire, so Inbox → Edit order
 * can paint immediately when the courier API is slow or down.
 */

const FETCH_TIMEOUT_MS = 15000;

let currentAbort = null;
let inflightPhone = '';

const csrfToken = () => (typeof document === 'undefined'
    ? ''
    : document.querySelector('meta[name="csrf-token"]')?.content ?? '');

const wireFor = (el) => {
    const id = el?.closest?.('[wire\\:id]')?.getAttribute('wire:id');

    return id && window.Livewire ? window.Livewire.find(id) : null;
};

const postStats = async (url, phone, signal) => {
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
            body: JSON.stringify({ phone }),
        });

        if (! response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        return await response.json().catch(() => ({}));
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', onAbort);
    }
};

const boot = () => {
    if (typeof document === 'undefined') {
        return;
    }

    document.querySelectorAll('[data-admin-steadfast-stats-root]').forEach((el) => {
        const pending = el.dataset.adminSteadfastStatsPending === '1';
        const url = el.dataset.adminSteadfastStatsUrl || '';
        const phone = (el.dataset.adminSteadfastStatsPhone || '').trim();

        if (! pending || ! url || phone === '') {
            return;
        }

        if (inflightPhone === phone) {
            return;
        }

        const wire = wireFor(el);

        if (! wire) {
            return;
        }

        currentAbort?.abort();

        const abort = new AbortController();

        currentAbort = abort;
        inflightPhone = phone;

        postStats(url, phone, abort.signal)
            .then(async (body) => {
                if (abort.signal.aborted || ! wire) {
                    return;
                }

                await wire.applySteadfastStats(
                    body?.stats ?? null,
                    body?.error ?? null,
                    body?.phone || phone,
                );
            })
            .catch(async () => {
                if (abort.signal.aborted || ! wire) {
                    return;
                }

                try {
                    await wire.settleSteadfastStats(phone);
                } catch {
                    // Spinner stays until the next successful poll.
                }
            })
            .finally(() => {
                if (inflightPhone === phone) {
                    inflightPhone = '';
                }

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
