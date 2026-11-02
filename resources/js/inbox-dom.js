export const csrfToken = () => (typeof document === 'undefined'
    ? ''
    : document.querySelector('meta[name="csrf-token"]')?.content ?? '');

export const inboxScrollToMessage = (id) => {
    const targetId = Number(id);
    if (! Number.isFinite(targetId) || targetId <= 0) {
        return false;
    }

    const el = document.getElementById(`inbox-msg-${targetId}`)
        || document.querySelector(`[data-inbox-msg-id="${targetId}"]`);
    if (! el) {
        return false;
    }

    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('is-quoted');
    window.setTimeout(() => el.classList.remove('is-quoted'), 1600);

    return true;
};

if (typeof window !== 'undefined') {
    window.inboxScrollToMessage = inboxScrollToMessage;

    document.addEventListener('click', (event) => {
        const quote = event.target?.closest?.('[data-inbox-quote-target]');
        if (! quote) {
            return;
        }

        const jumped = inboxScrollToMessage(quote.getAttribute('data-inbox-quote-target'));
        if (jumped) {
            event.preventDefault();
            event.stopPropagation();
        }
    });
}

export const livewireFor = (el, wireId = null) => {
    const id = wireId
        || el?.closest?.('[wire\\:id]')?.getAttribute('wire:id');

    return id && window.Livewire ? window.Livewire.find(id) : null;
};
