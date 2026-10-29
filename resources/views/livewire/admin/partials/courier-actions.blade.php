@php
    $receivable = (float) ($summary['receivable'] ?? 0);
@endphp

<div @class([
    'flex flex-wrap items-center gap-x-3 gap-y-2',
    'justify-end' => ($align ?? 'start') === 'end',
])>
    @if ($receivable != 0.0)
        <button type="button"
            wire:click="openNeutralize({{ $courier->id }})"
            class="inline-flex min-h-11 items-center text-sm text-[#6B6459] hover:text-[#C9A227] hover:underline lg:min-h-0">
            Neutralize
        </button>
    @endif
    @if ((float) $courier->balance > 0)
        <button type="button"
            wire:click="openWithdraw({{ $courier->id }})"
            class="inline-flex min-h-11 items-center text-sm text-[#6B6459] hover:text-[#C9A227] hover:underline lg:min-h-0">
            Withdraw
        </button>
    @endif
    <a href="{{ route('admin.couriers.edit', $courier) }}" wire:navigate
        class="inline-flex min-h-11 items-center text-sm text-[#C9A227] hover:underline lg:min-h-0">
        Edit
    </a>
    @if (! $courier->is_default && $courier->orders_count === 0)
        <button type="button"
            wire:click="delete({{ $courier->id }})"
            wire:confirm="Delete “{{ $courier->name }}”?"
            class="inline-flex min-h-11 items-center text-sm text-rose-600 hover:underline lg:min-h-0">
            Delete
        </button>
    @endif
</div>
