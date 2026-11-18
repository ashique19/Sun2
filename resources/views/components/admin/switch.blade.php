@props([
    'on' => false,
])

<button
    type="button"
    role="switch"
    dir="ltr"
    aria-checked="{{ $on ? 'true' : 'false' }}"
    {{ $attributes->class('admin-switch') }}
>
    <span class="admin-switch__knob" aria-hidden="true"></span>
</button>
