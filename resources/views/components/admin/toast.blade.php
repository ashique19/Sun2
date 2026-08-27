@props([
    'message',
    'type' => 'success', // success | error | warning
    'dismissMethod' => null,
    'ms' => 4000,
    'bottom' => 'bottom-28 md:bottom-6',
    'dismissable' => false,
    'prefix' => null,
])

@php
    $styles = match ($type) {
        'error' => 'border-rose-200 bg-rose-50 text-rose-900',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-950',
        default => 'border-emerald-200 bg-emerald-50 text-emerald-900',
    };
    $role = $type === 'error' ? 'alert' : 'status';
    $key = md5(($type).'|'.($prefix ?? '').'|'.(string) $message);
@endphp

<div
    {{ $attributes->class([
        'fixed left-1/2 z-[70] w-[min(24rem,calc(100%-2rem))] -translate-x-1/2 rounded-xl border px-3 py-2.5 text-sm shadow-lg',
        $bottom,
        $styles,
    ]) }}
    wire:key="admin-toast-{{ $key }}"
    x-data="{ show: true }"
    x-show="show"
    x-transition.opacity.duration.200ms
    x-init="setTimeout(() => { show = false; @if ($dismissMethod) $wire.{{ $dismissMethod }}() @endif }, {{ (int) $ms }})"
    role="{{ $role }}"
    data-admin-toast="{{ $type }}"
>
    <div @class(['flex items-start gap-2' => $dismissable, 'text-center' => ! $dismissable])>
        <p @class(['min-w-0 flex-1' => $dismissable, 'break-words' => true])>
            @if ($prefix)
                <span class="font-medium">{{ $prefix }}</span>
            @endif
            {{ $message }}
        </p>
        @if ($dismissable && $dismissMethod)
            <button type="button"
                wire:click="{{ $dismissMethod }}"
                class="shrink-0 text-xs font-semibold opacity-80 hover:opacity-100"
                aria-label="Dismiss">
                Dismiss
            </button>
        @endif
    </div>
</div>
