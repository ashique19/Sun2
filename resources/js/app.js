import '../css/app.css';

/**
 * Copy text to clipboard with execCommand fallback (HTTP / older browsers).
 * Used by Admin Orders customer cells.
 */
window.sunCopyText = async function sunCopyText(text, feedbackEl) {
    const value = String(text ?? '').trim();
    if (! value) {
        return false;
    }

    let ok = false;

    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(value);
            ok = true;
        }
    } catch {
        ok = false;
    }

    if (! ok) {
        const ta = document.createElement('textarea');
        ta.value = value;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '0';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            ok = document.execCommand('copy');
        } catch {
            ok = false;
        }
        document.body.removeChild(ta);
    }

    if (ok && feedbackEl) {
        feedbackEl.classList.remove('hidden');
        clearTimeout(feedbackEl._sunCopyTimer);
        feedbackEl._sunCopyTimer = setTimeout(() => {
            feedbackEl.classList.add('hidden');
        }, 1200);
    }

    return ok;
};

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
