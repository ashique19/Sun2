@props([
    'options' => [],
    'placeholder' => 'Select…',
    'disabled' => false,
    'emptyLabel' => 'No matches',
])

@php
    $normalized = collect($options)
        ->map(function ($option) {
            if (is_array($option)) {
                return [
                    'value' => (string) ($option['value'] ?? $option['id'] ?? ''),
                    'label' => (string) ($option['label'] ?? $option['name'] ?? ''),
                ];
            }

            return [
                'value' => (string) ($option->id ?? ''),
                'label' => (string) ($option->name ?? ''),
            ];
        })
        ->filter(fn (array $option) => $option['value'] !== '' && $option['label'] !== '')
        ->values()
        ->all();

    $wireModel = $attributes->wire('model');
@endphp

<div
    {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'relative min-w-0']) }}
    x-data="{
        open: false,
        search: '',
        value: @entangle($wireModel),
        options: {{ \Illuminate\Support\Js::from($normalized) }},
        placeholder: {{ \Illuminate\Support\Js::from($placeholder) }},
        disabled: {{ $disabled ? 'true' : 'false' }},
        emptyLabel: {{ \Illuminate\Support\Js::from($emptyLabel) }},
        panelStyle: '',
        get selectedLabel() {
            const current = String(this.value ?? '');
            if (! current) return '';
            return this.options.find(option => String(option.value) === current)?.label ?? '';
        },
        get filtered() {
            const query = this.search.trim().toLowerCase();
            if (! query) return this.options;
            return this.options.filter(option => option.label.toLowerCase().includes(query));
        },
        positionPanel() {
            const trigger = this.$refs.trigger;
            if (! trigger) return;
            const rect = trigger.getBoundingClientRect();
            const maxHeight = 240;
            const gap = 4;
            const spaceBelow = window.innerHeight - rect.bottom - gap;
            const spaceAbove = rect.top - gap;
            const openUp = spaceBelow < 160 && spaceAbove > spaceBelow;
            const maxPanel = Math.min(maxHeight, openUp ? spaceAbove - 8 : spaceBelow - 8);

            this.panelStyle = openUp
                ? [
                    'position: fixed',
                    'top: auto',
                    'bottom: ' + (window.innerHeight - rect.top + gap) + 'px',
                    'left: ' + rect.left + 'px',
                    'width: ' + rect.width + 'px',
                    'max-height: ' + Math.max(120, maxPanel) + 'px',
                    'z-index: 100050',
                ].join(';')
                : [
                    'position: fixed',
                    'top: ' + (rect.bottom + gap) + 'px',
                    'bottom: auto',
                    'left: ' + rect.left + 'px',
                    'width: ' + rect.width + 'px',
                    'max-height: ' + Math.max(120, maxPanel) + 'px',
                    'z-index: 100050',
                ].join(';');
        },
        toggle() {
            if (this.disabled) return;
            this.open = ! this.open;
            if (this.open) {
                this.search = '';
                this.positionPanel();
                this.$nextTick(() => this.$refs.search?.focus());
            }
        },
        select(option) {
            this.value = Number(option.value);
            this.search = '';
            this.open = false;
        },
        clear() {
            if (this.disabled) return;
            this.value = null;
            this.search = '';
            this.open = false;
        },
        close() {
            this.open = false;
            this.search = '';
        },
    }"
    @keydown.escape.window="close()"
    @click.outside="close()"
    @resize.window="open && positionPanel()"
    @scroll.window.capture="open && positionPanel()"
>
    <button
        type="button"
        x-ref="trigger"
        @click="toggle()"
        :disabled="disabled"
        class="flex w-full max-w-full items-center gap-2 rounded-lg border border-[#E0D6C2] bg-white px-3 py-2 text-left text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227] disabled:cursor-not-allowed disabled:opacity-50"
        :aria-expanded="open.toString()"
        aria-haspopup="listbox"
    >
        <span class="min-w-0 flex-1 truncate" :class="selectedLabel ? 'text-[#1E1E1E]' : 'text-[#8C8474]'" x-text="selectedLabel || placeholder"></span>
        <span
            x-show="value"
            x-cloak
            @click.stop="clear()"
            class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded text-[#8C8474] hover:bg-[#FAF6EF] hover:text-[#1E1E1E]"
            title="Clear"
            aria-label="Clear selection"
        >&times;</span>
        <svg class="h-4 w-4 shrink-0 text-[#8C8474]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
        </svg>
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        x-ref="panel"
        :style="panelStyle"
        class="flex flex-col overflow-hidden rounded-lg border border-[#E0D6C2] bg-white shadow-lg"
        role="listbox"
    >
        <div class="shrink-0 border-b border-[#EFE7D6] p-2">
            <input
                type="search"
                x-ref="search"
                x-model="search"
                @keydown.enter.prevent="filtered.length === 1 && select(filtered[0])"
                placeholder="Type to filter…"
                class="w-full rounded-md border border-[#E0D6C2] px-3 py-1.5 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"
            >
        </div>
        <ul class="min-h-0 flex-1 overflow-y-auto py-1 text-sm">
            <template x-if="filtered.length === 0">
                <li class="px-3 py-2 text-[#8C8474]" x-text="emptyLabel"></li>
            </template>
            <template x-for="option in filtered" :key="option.value">
                <li>
                    <button
                        type="button"
                        @click="select(option)"
                        class="flex w-full px-3 py-2 text-left text-[#1E1E1E] hover:bg-[#FAF6EF]"
                        :class="String(value) === String(option.value) ? 'bg-[#FAF6EF] font-medium' : ''"
                        x-text="option.label"
                    ></button>
                </li>
            </template>
        </ul>
    </div>
</div>
