/**
 * Inbox conversation cache.
 *
 * While /admin/inbox is open, recent threads are stored in localStorage.
 * The next click paints those messages immediately; Livewire still loads
 * the authoritative thread and replaces this overlay.
 */

const VERSION = 1;
const MAX_THREADS = 40;
const MAX_BYTES = 3_500_000;
const PREFETCH_BATCH = 6;
const PREFETCH_GAP_MS = 8000;
const CACHE_TTL_MS = 7 * 24 * 60 * 60 * 1000;

let paintedConversationId = 0;
let prefetchTimer = null;
let lastPrefetchAt = 0;

const csrfToken = () => (typeof document === 'undefined'
    ? ''
    : document.querySelector('meta[name="csrf-token"]')?.content ?? '');

const root = () => document.querySelector('[data-inbox-snapshot-root]');

const storageKey = (userId) => `sun-inbox-cache:v${VERSION}:${userId}`;

const readCache = (userId) => {
    if (! userId) {
        return { userId, savedAt: 0, list: [], threads: {} };
    }

    try {
        const raw = localStorage.getItem(storageKey(userId));
        if (! raw) {
            return { userId, savedAt: 0, list: [], threads: {} };
        }

        const parsed = JSON.parse(raw);
        if (! parsed || parsed.userId !== userId || typeof parsed.threads !== 'object') {
            return { userId, savedAt: 0, list: [], threads: {} };
        }

        if (parsed.savedAt && Date.now() - Number(parsed.savedAt) > CACHE_TTL_MS) {
            localStorage.removeItem(storageKey(userId));

            return { userId, savedAt: 0, list: [], threads: {} };
        }

        return {
            userId,
            savedAt: Number(parsed.savedAt) || 0,
            list: Array.isArray(parsed.list) ? parsed.list : [],
            threads: parsed.threads && typeof parsed.threads === 'object' ? parsed.threads : {},
        };
    } catch {
        return { userId, savedAt: 0, list: [], threads: {} };
    }
};

const writeCache = (cache) => {
    if (! cache?.userId) {
        return;
    }

    const threadIds = Object.keys(cache.threads);
    if (threadIds.length > MAX_THREADS) {
        const ranked = threadIds
            .map((id) => ({ id, at: Number(cache.threads[id]?.savedAt) || 0 }))
            .sort((a, b) => a.at - b.at);
        ranked.slice(0, threadIds.length - MAX_THREADS).forEach((row) => {
            delete cache.threads[row.id];
        });
    }

    cache.savedAt = Date.now();

    try {
        let payload = JSON.stringify(cache);
        while (payload.length > MAX_BYTES) {
            const oldest = Object.keys(cache.threads)
                .map((id) => ({ id, at: Number(cache.threads[id]?.savedAt) || 0 }))
                .sort((a, b) => a.at - b.at)[0];
            if (! oldest) {
                break;
            }
            delete cache.threads[oldest.id];
            payload = JSON.stringify(cache);
        }
        localStorage.setItem(storageKey(cache.userId), payload);
    } catch {
        // Quota or private mode — skip cache; Livewire still works.
    }
};

const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');

const messageHtml = (message) => {
    const outbound = message.direction === 'outbound';
    const bubble = outbound
        ? 'rounded-2xl rounded-br-md bg-[#C9A227] text-white'
        : 'rounded-2xl rounded-bl-md bg-white text-[#1E1E1E] ring-1 ring-[#EBE3D4]';
    const wrap = outbound ? 'justify-end' : 'justify-start';
    const timeClass = outbound ? 'text-white/75' : 'text-[#8C8474]';

    let inner = '';
    if (message.reply_preview) {
        inner += `<div class="mb-2 rounded-xl px-2 py-1 text-[11px] ${outbound ? 'bg-black/10 text-white/90' : 'border border-[#E7DFCF] bg-[#FAF6EF] text-[#6B6459]'}">`
            + `<span class="font-semibold">Replying to</span>`
            + `<span class="block truncate">${escapeHtml(message.reply_preview)}</span></div>`;
    }
    if (message.body) {
        inner += `<p class="whitespace-pre-wrap break-words leading-relaxed">${escapeHtml(message.body)}</p>`;
    }
    if (message.is_image && message.media_url) {
        inner += `<div class="relative mt-2 overflow-hidden rounded-xl ${outbound ? 'bg-black/10' : 'bg-[#FAF6EF]'}">`
            + `<img src="${escapeHtml(message.media_url)}" alt="Photo" class="max-h-72 w-full object-contain" loading="lazy"></div>`;
    } else if (message.is_image) {
        inner += `<p class="mt-1 text-xs ${outbound ? 'text-white/90' : 'text-[#8C8474]'}">Photo</p>`;
    }
    if (message.sent_at_label) {
        inner += `<div class="mt-1 flex items-center justify-end"><p class="text-[10px] tabular-nums ${timeClass}">${escapeHtml(message.sent_at_label)}</p></div>`;
    }

    return `<div class="flex w-full ${wrap}"><div class="max-w-[82%] px-3 py-2 text-sm shadow-sm sm:max-w-[70%] ${bubble}">${inner}</div></div>`;
};

const overlay = () => document.querySelector('[data-inbox-cache-thread]');

const hideOverlay = () => {
    const el = overlay();
    if (! el) {
        return;
    }

    el.hidden = true;
    el.setAttribute('aria-hidden', 'true');
    paintedConversationId = 0;
    document.querySelector('[data-inbox-mobile-thread-sheet]')?.removeAttribute('data-inbox-skip-opening');
};

const showOverlay = (thread) => {
    const el = overlay();
    if (! el || ! thread) {
        return false;
    }

    const name = el.querySelector('[data-inbox-cache-thread-name]');
    const windowEl = el.querySelector('[data-inbox-cache-thread-window]');
    const scroller = el.querySelector('[data-inbox-cache-thread-messages]');
    if (name) {
        name.textContent = thread.name || 'Conversation';
    }
    if (windowEl) {
        windowEl.textContent = thread.window ? 'Within 24h' : 'Outside 24h window';
    }
    if (scroller) {
        scroller.innerHTML = (thread.messages || []).map(messageHtml).join('');
        scroller.scrollTop = scroller.scrollHeight;
    }

    el.hidden = false;
    el.setAttribute('aria-hidden', 'false');
    paintedConversationId = Number(thread.id) || 0;
    document.querySelector('[data-inbox-mobile-thread-sheet]')?.setAttribute('data-inbox-skip-opening', '1');

    return true;
};

const persistFromDom = () => {
    const host = root();
    if (! host) {
        hideOverlay();

        return;
    }

    const userId = Number(host.getAttribute('data-inbox-user-id') || '0');
    if (! userId) {
        return;
    }

    const cache = readCache(userId);
    cache.list = [];
    host.querySelectorAll('[data-inbox-conversation-id]').forEach((button) => {
        const id = Number(button.getAttribute('data-inbox-conversation-id') || '0');
        if (id > 0) {
            cache.list.push({
                id,
                name: (button.getAttribute('data-inbox-conversation-name') || button.textContent || '').trim(),
            });
        }
    });

    const payloadRaw = host.getAttribute('data-inbox-open-thread') || '';
    if (payloadRaw) {
        try {
            const thread = JSON.parse(payloadRaw);
            if (thread?.id) {
                cache.threads[String(thread.id)] = { ...thread, savedAt: Date.now() };
                if (paintedConversationId === Number(thread.id)) {
                    hideOverlay();
                }
            }
        } catch {
            // Ignore a truncated attribute; prefetch can fill it.
        }
    } else if (paintedConversationId) {
        hideOverlay();
    }

    writeCache(cache);
    schedulePrefetch(host, cache);
};

const schedulePrefetch = (host, cache) => {
    const url = host.getAttribute('data-inbox-snapshot-url') || '';
    if (! url) {
        return;
    }

    const missing = cache.list
        .map((row) => Number(row.id))
        .filter((id) => id > 0 && ! cache.threads[String(id)])
        .slice(0, PREFETCH_BATCH);

    if (missing.length === 0) {
        return;
    }

    clearTimeout(prefetchTimer);
    prefetchTimer = setTimeout(() => prefetch(url, missing, cache.userId), 400);
};

const prefetch = async (url, ids, userId) => {
    if (Date.now() - lastPrefetchAt < PREFETCH_GAP_MS) {
        return;
    }

    lastPrefetchAt = Date.now();

    try {
        const query = new URL(url, window.location.origin);
        ids.forEach((id) => query.searchParams.append('ids[]', String(id)));

        const response = await fetch(query.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (! response.ok) {
            return;
        }

        const body = await response.json().catch(() => ({}));
        if (Number(body.user_id) !== userId || ! Array.isArray(body.threads)) {
            return;
        }

        const cache = readCache(userId);
        body.threads.forEach((thread) => {
            if (thread?.id) {
                cache.threads[String(thread.id)] = { ...thread, savedAt: Date.now() };
            }
        });
        writeCache(cache);
    } catch {
        // Prefetch is optional.
    }
};

const onConversationClick = (event) => {
    const button = event.target?.closest?.('[data-inbox-conversation-id]');
    if (! button || ! root()?.contains(button)) {
        return;
    }

    const conversationId = Number(button.getAttribute('data-inbox-conversation-id') || '0');
    const userId = Number(root()?.getAttribute('data-inbox-user-id') || '0');
    if (! conversationId || ! userId) {
        return;
    }

    const thread = readCache(userId).threads[String(conversationId)];
    if (! thread?.messages) {
        return;
    }

    showOverlay(thread);
};

const boot = () => {
    if (typeof document === 'undefined') {
        return;
    }

    persistFromDom();
};

if (typeof document !== 'undefined') {
    document.addEventListener('click', onConversationClick, true);

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

export { readCache, showOverlay };
