// Inbox photo → product matching runs here, outside Livewire: each pending photo is
// a POST to admin.inbox.image-match, a few in parallel, and the inbox re-reads the
// persisted results (debounced) so chips appear progressively while staff keep working.

const queue = [];
const started = new Set();
let active = 0;
let refreshTimer = null;

const concurrency = () => Math.max(1, Number(document.querySelector('meta[name="inbox-image-match-concurrency"]')?.content) || 3);

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const scheduleRefresh = (wire) => {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
        wire?.refreshInboundImageMatches?.();
    }, 150);
};

const pump = () => {
    while (active < concurrency() && queue.length > 0) {
        const job = queue.shift();
        active++;

        fetch(job.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then((response) => {
                if (! response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                return response.json();
            })
            .then(() => job.done(true))
            .catch(() => job.done(false))
            .finally(() => {
                active--;
                pump();
            });
    }
};

const enqueue = (url, done) => {
    if (! url || started.has(url)) {
        return;
    }

    started.add(url);
    queue.push({ url, done });
    pump();
};

const registerInboxImageMatch = () => {
    Alpine.data('inboxImageMatchPending', (url) => ({
        failed: false,

        init() {
            const wire = this.$wire;

            enqueue(url, (ok) => {
                if (! ok) {
                    this.failed = true;
                    window.dispatchEvent(new CustomEvent('inbox-image-match-failed', { detail: { url } }));
                }

                scheduleRefresh(wire);
            });
        },
    }));
};

if (window.Alpine) {
    registerInboxImageMatch();
} else {
    document.addEventListener('alpine:init', registerInboxImageMatch);
}
