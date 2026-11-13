@props([
    'order',
    'compact' => false,
])

@php($variants = \App\Support\OrderPrintVariant::cases())

<div
    {{ $attributes->class(['relative inline-flex shrink-0']) }}
    x-data="{
        open: false,
        menuStyle: 'position:fixed;z-index:100050;width:18rem;max-width:calc(100vw - 1rem);',
        positionMenu() {
            const trigger = this.$refs.trigger;
            if (! trigger) {
                return;
            }
            const rect = trigger.getBoundingClientRect();
            const gap = 4;
            const width = Math.min(288, window.innerWidth - 16);
            let left = rect.right - width;
            if (left < 8) {
                left = 8;
            }
            if (left + width > window.innerWidth - 8) {
                left = Math.max(8, window.innerWidth - width - 8);
            }
            let top = rect.bottom + gap;
            this.menuStyle = [
                'position:fixed',
                'z-index:100050',
                'width:' + width + 'px',
                'max-width:calc(100vw - 1rem)',
                'left:' + left + 'px',
                'top:' + top + 'px',
            ].join(';');
            this.$nextTick(() => {
                const menu = this.$refs.menu;
                if (! menu || ! this.open) {
                    return;
                }
                const height = menu.getBoundingClientRect().height;
                if (top + height > window.innerHeight - 8) {
                    const up = rect.top - gap - height;
                    top = up >= 8 ? up : Math.max(8, window.innerHeight - height - 8);
                    this.menuStyle = this.menuStyle.replace(/top:[^;]+/, 'top:' + top + 'px');
                }
            });
        },
        toggle() {
            this.open = ! this.open;
            if (this.open) {
                this.positionMenu();
                this.$nextTick(() => this.positionMenu());
            }
        },
    }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    @resize.window="open && positionMenu()"
    @scroll.window.capture="open && positionMenu()"
    data-order-print-menu
>
    <button type="button"
        x-ref="trigger"
        @click.stop="toggle()"
        title="Print"
        aria-label="Print"
        data-order-action="print"
        :aria-expanded="open.toString()"
        @class([
            'inline-flex items-center justify-center rounded-lg border border-[#E0D6C2] bg-white text-[#6B6459] hover:bg-[#FAF6EF]',
            'h-8 w-8' => $compact,
            'h-9 w-9 opacity-80 hover:opacity-100' => ! $compact,
        ])>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
            <path fill="currentColor" d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/>
        </svg>
    </button>

    <div
        x-ref="menu"
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        :style="menuStyle"
        role="menu"
        class="rounded-lg border border-[#EFE7D6] bg-white py-1 shadow-lg"
    >
        @foreach ($variants as $printVariant)
            <a href="{{ route('admin.orders.print', ['order' => $order, 'variant' => $printVariant->value]) }}"
                target="_blank"
                rel="noopener noreferrer"
                @click="open = false"
                role="menuitem"
                data-order-print-variant="{{ $printVariant->value }}"
                class="block px-3 py-2.5 text-left text-sm font-medium leading-snug text-[#1E1E1E] hover:bg-[#FAF6EF]">
                {{ $printVariant->label() }}
            </a>
        @endforeach
    </div>
</div>
