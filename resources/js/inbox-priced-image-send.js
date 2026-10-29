/**
 * Inbox P.img clicks are queued here, outside Livewire.
 *
 * Facebook image upload is slow; Livewire single-flights the next click until
 * the previous send finishes. Each click is recorded immediately, then sends
 * run one at a time so Messenger order stays stable.
 */

const FETCH_TIMEOUT_MS = 45000;
const SENT_RESET_MS = 2000;

/** @type {Array<{key: string, productId: number, conversationId: number, replyTo: number, url: string, wireId: ?string}>} */
const queue = [];
const inflightKeys = new Set();
/** @type {Map<string, {status: string, error: string}>} */
const states = new Map();
let draining = false;
let refreshTimer = null;

const csrfToken = () => (typeof document === 'undefined'
    ? ''
    : document.querySelector('meta[name="csrf-token"]')?.content ?? '');

const closestDataset = (el, name) => {
    const node = el?.closest?.(`[${name}]`);

    return node?.getAttribute(name) || '';
};

const jobKey = (productId, conversationId, replyTo) => `${conversationId}:${productId}:${replyTo}`;

const wireFor = (wireId, el) => {
    const id = wireId
        || el?.closest?.('[data-inbox-priced-image-wire]')?.getAttribute('data-inbox-priced-image-wire')
        || el?.closest?.('[wire\\:id]')?.getAttribute('wire:id');

    return id && window.Livewire ? window.Livewire.find(id) : null;
};

const buttonsFor = (key) => {
    const found = [];

    document.querySelectorAll('[data-inbox-priced-image-send]').forEach((button) => {
        const job = jobFromButton(button);
        if (job?.key === key) {
            found.push(button);
        }
    });

    return found;
};

const setButtonState = (button, status, error) => {
    if (! button) {
        return;
    }

    const label = button.querySelector('[data-inbox-priced-image-label]') || button;
    const busy = status === 'queued' || status === 'sending';

    button.disabled = busy;
    button.setAttribute('aria-busy', busy ? 'true' : 'false');
    button.dataset.inboxPricedImageStatus = status;

    if (status === 'queued') {
        label.textContent = 'Queued';
    } else if (status === 'sending') {
        label.textContent = 'Sending';
    } else if (status === 'sent') {
        label.textContent = 'Sent';
    } else {
        label.textContent = 'P.img';
    }

    if (status === 'error' && error) {
        button.title = error;
    } else if (status === 'sent') {
        button.title = 'Priced image sent';
    } else if (status === 'queued' || status === 'sending') {
        button.title = status === 'queued' ? 'Queued — sending shortly' : 'Sending priced product image';
    } else {
        button.title = 'Send priced product image';
    }
};

const setState = (key, status, error = '') => {
    if (status === 'idle') {
        states.delete(key);
    } else {
        states.set(key, { status, error });
    }

    buttonsFor(key).forEach((button) => setButtonState(button, status, error));
};

const scheduleRefresh = (wire, conversationId) => {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
        wire?.refreshFromRealtime?.(conversationId);
    }, 250);
};

const postSend = async (job) => {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

    try {
        const response = await fetch(job.url, {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                conversation_id: job.conversationId,
                product_id: job.productId,
                reply_to_message_id: job.replyTo > 0 ? job.replyTo : null,
            }),
        });

        const body = await response.json().catch(() => ({}));

        if (! response.ok || ! body?.ok) {
            throw new Error(body?.error || `HTTP ${response.status}`);
        }

        return body;
    } finally {
        clearTimeout(timer);
    }
};

const drain = async () => {
    if (draining) {
        return;
    }

    draining = true;

    try {
        while (queue.length > 0) {
            const job = queue.shift();
            setState(job.key, 'sending');

            try {
                await postSend(job);
                setState(job.key, 'sent');
                scheduleRefresh(wireFor(job.wireId), job.conversationId);
                setTimeout(() => {
                    if (states.get(job.key)?.status === 'sent') {
                        setState(job.key, 'idle');
                    }
                }, SENT_RESET_MS);
            } catch (error) {
                const message = error?.name === 'AbortError'
                    ? 'Timed out sending priced image.'
                    : (error?.message || 'Failed to send priced image.');
                setState(job.key, 'error', message);
            } finally {
                inflightKeys.delete(job.key);
            }
        }
    } finally {
        draining = false;
        if (queue.length > 0) {
            drain();
        }
    }
};

const jobFromButton = (button) => {
    const productId = Number(button.getAttribute('data-product-id') || '0');
    const conversationId = Number(
        button.getAttribute('data-conversation-id')
        || closestDataset(button, 'data-inbox-priced-image-conversation')
        || '0',
    );
    const replyTo = Number(
        button.getAttribute('data-reply-to-message-id')
        || closestDataset(button, 'data-inbox-priced-image-reply-to')
        || '0',
    );
    const url = button.getAttribute('data-send-url')
        || closestDataset(button, 'data-inbox-priced-image-url');
    const wireId = closestDataset(button, 'data-inbox-priced-image-wire')
        || button.closest('[wire\\:id]')?.getAttribute('wire:id')
        || '';

    if (! Number.isInteger(productId) || productId <= 0) {
        return null;
    }

    if (! Number.isInteger(conversationId) || conversationId <= 0) {
        return null;
    }

    if (! url) {
        return null;
    }

    const key = jobKey(productId, conversationId, Number.isInteger(replyTo) && replyTo > 0 ? replyTo : 0);

    button.dataset.inboxPricedImageKey = key;

    return {
        key,
        productId,
        conversationId,
        replyTo: Number.isInteger(replyTo) && replyTo > 0 ? replyTo : 0,
        url,
        wireId: wireId || null,
    };
};

const enqueueFromButton = (button) => {
    const job = jobFromButton(button);
    if (! job) {
        return;
    }

    if (inflightKeys.has(job.key)) {
        return;
    }

    inflightKeys.add(job.key);
    queue.push(job);
    setState(job.key, 'queued');
    drain();
};

const onClick = (event) => {
    const button = event.target?.closest?.('[data-inbox-priced-image-send]');
    if (! button || button.disabled) {
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    enqueueFromButton(button);
};

const boot = () => {
    if (typeof document === 'undefined') {
        return;
    }

    document.querySelectorAll('[data-inbox-priced-image-send]').forEach((button) => {
        const job = jobFromButton(button);
        if (job && states.has(job.key)) {
            const state = states.get(job.key);
            setButtonState(button, state.status, state.error);
        }
    });
};

if (typeof document !== 'undefined') {
    document.addEventListener('click', onClick, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    document.addEventListener('livewire:navigated', boot);
    document.addEventListener('livewire:init', () => {
        window.Livewire?.hook?.('commit', ({ succeed }) => {
            succeed(() => queueMicrotask(boot));
        });
    });
}

export { enqueueFromButton };
