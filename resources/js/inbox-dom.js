export const csrfToken = () => (typeof document === 'undefined'
    ? ''
    : document.querySelector('meta[name="csrf-token"]')?.content ?? '');

export const livewireFor = (el, wireId = null) => {
    const id = wireId
        || el?.closest?.('[wire\\:id]')?.getAttribute('wire:id');

    return id && window.Livewire ? window.Livewire.find(id) : null;
};
