import { livewireFor } from './inbox-dom.js';

export const REPLY_THRESHOLD = 56;
export const MAX_PULL = 72;
export const EDGE_GUARD = 24;
export const AXIS_LOCK = 10;

const IGNORE_SELECTOR = 'button, a, input, textarea, select, [contenteditable], [data-inbox-no-swipe], [role=dialog]';

export function swipeAxis(dx, dy) {
    if (Math.abs(dx) < AXIS_LOCK && Math.abs(dy) < AXIS_LOCK) {
        return null;
    }

    return Math.abs(dx) > Math.abs(dy) ? 'horizontal' : 'vertical';
}

export function replyDirection(isOutbound) {
    return isOutbound ? -1 : 1;
}

export function clampedPull(dx, direction) {
    const along = dx * direction;
    if (along <= 0) {
        return 0;
    }

    if (along <= MAX_PULL) {
        return along;
    }

    return MAX_PULL + (along - MAX_PULL) * 0.18;
}

export function shouldCommitReply(pull) {
    return pull >= REPLY_THRESHOLD;
}

const elementFrom = (target) => (target instanceof Element
    ? target
    : (target?.parentElement instanceof Element ? target.parentElement : null));

export function shouldIgnoreSwipeTarget(target) {
    const el = elementFrom(target);
    if (! el) {
        return true;
    }

    return Boolean(el.closest(IGNORE_SELECTOR));
}

export function messageRowFromEvent(target) {
    const el = elementFrom(target);
    if (! el) {
        return null;
    }

    const row = el.closest('[data-inbox-swipe-reply]');
    if (! row || ! row.closest('[data-inbox-thread-scroller]')) {
        return null;
    }

    return row;
}

const bubbleOf = (row) => row.querySelector('[data-inbox-msg-bubble]') || row.querySelector(':scope > div');

export function applySwipe(row, pull, direction) {
    const bubble = bubbleOf(row);
    if (! bubble) {
        return;
    }

    const x = pull * direction;
    bubble.style.transition = 'none';
    bubble.style.transform = x ? `translate3d(${x}px, 0, 0)` : '';
    row.classList.toggle('is-swipe-reply', pull > 8);
    row.classList.toggle('is-swipe-reply-armed', shouldCommitReply(pull));
}

export function resetSwipe(row) {
    const bubble = bubbleOf(row);
    row.classList.remove('is-swipe-reply', 'is-swipe-reply-armed');
    if (! bubble) {
        return;
    }

    bubble.style.transition = 'transform 160ms ease';
    bubble.style.transform = '';
}

const focusComposer = () => {
    const textarea = document.querySelector('[data-inbox-reply-text]');
    textarea?.focus?.();
    textarea?.scrollIntoView?.({ block: 'nearest' });
};

const commitReply = (row) => {
    const messageId = Number(row.getAttribute('data-inbox-msg-id'));
    if (! Number.isInteger(messageId) || messageId <= 0) {
        return;
    }

    row.dispatchEvent(new CustomEvent('inbox-swipe-reply', {
        bubbles: true,
        detail: { messageId },
    }));

    const wire = livewireFor(row);
    const result = wire?.call
        ? wire.call('setReplyTo', messageId)
        : wire?.setReplyTo?.(messageId);

    if (result && typeof result.then === 'function') {
        result.then(() => focusComposer()).catch(() => focusComposer());

        return;
    }

    focusComposer();
};

export function installInboxSwipeReply(root = typeof document === 'undefined' ? null : document) {
    if (! root || root.__inboxSwipeReplyInstalled) {
        return;
    }

    root.__inboxSwipeReplyInstalled = true;

    let session = null;

    const clearSession = () => {
        session = null;
    };

    const onDown = (event) => {
        if (event.button != null && event.button !== 0) {
            return;
        }

        if (event.clientX < EDGE_GUARD) {
            return;
        }

        if (shouldIgnoreSwipeTarget(event.target)) {
            return;
        }

        const row = messageRowFromEvent(event.target);
        if (! row) {
            return;
        }

        session = {
            row,
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            axis: null,
            pull: 0,
            direction: row.hasAttribute('data-inbox-msg-outbound') ? -1 : 1,
            armedHaptic: false,
        };
    };

    const onMove = (event) => {
        if (! session || event.pointerId !== session.pointerId) {
            return;
        }

        const dx = event.clientX - session.startX;
        const dy = event.clientY - session.startY;

        if (! session.axis) {
            session.axis = swipeAxis(dx, dy);
            if (session.axis === 'vertical') {
                clearSession();

                return;
            }
            if (session.axis !== 'horizontal') {
                return;
            }
        }

        if (session.axis !== 'horizontal') {
            return;
        }

        session.pull = clampedPull(dx, session.direction);
        applySwipe(session.row, session.pull, session.direction);

        if (shouldCommitReply(session.pull) && ! session.armedHaptic) {
            session.armedHaptic = true;
            navigator.vibrate?.(10);
        }
    };

    const onUp = (event) => {
        if (! session || (event.pointerId != null && event.pointerId !== session.pointerId)) {
            return;
        }

        const { row, pull, axis } = session;
        clearSession();
        resetSwipe(row);

        if (axis === 'horizontal' && shouldCommitReply(pull)) {
            commitReply(row);
        }
    };

    root.addEventListener('pointerdown', onDown);
    root.addEventListener('pointermove', onMove);
    root.addEventListener('pointerup', onUp);
    root.addEventListener('pointercancel', onUp);
}

if (typeof document !== 'undefined') {
    installInboxSwipeReply(document);
}
