const registerRichTextEditor = () => {
    Alpine.data('adminRichTextEditor', (property) => ({
        property,
        syncingFromWire: false,
        init() {
            this.applyFromWire(this.$wire.get(this.property) ?? '');

            this.$wire.$watch(this.property, (value) => {
                if (this.syncingFromWire) {
                    return;
                }

                const next = value ?? '';
                if (this.normalize(this.$refs.editor.innerHTML) === this.normalize(next)) {
                    return;
                }

                this.applyFromWire(next);
            });
        },
        normalize(html) {
            return String(html ?? '')
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        },
        applyFromWire(html) {
            this.syncingFromWire = true;
            const value = String(html ?? '');
            this.$refs.editor.innerHTML = value !== '' ? value : '';
            queueMicrotask(() => {
                this.syncingFromWire = false;
            });
        },
        pushToWire() {
            if (this.syncingFromWire) {
                return;
            }

            const html = this.$refs.editor.innerHTML;
            const empty = this.$refs.editor.innerText.trim() === '';
            this.$wire.set(this.property, empty ? '' : html);
        },
        command(cmd, value = null) {
            this.$refs.editor.focus();
            document.execCommand(cmd, false, value);
            this.pushToWire();
        },
        formatBlock(tag) {
            this.$refs.editor.focus();
            document.execCommand('formatBlock', false, tag);
            this.pushToWire();
        },
        link() {
            const url = window.prompt('Link URL', 'https://');
            if (! url) {
                return;
            }
            this.command('createLink', url);
        },
    }));
};

if (window.Alpine) {
    registerRichTextEditor();
} else {
    document.addEventListener('alpine:init', registerRichTextEditor);
}
