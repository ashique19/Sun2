@php
    $inputClass = ($tone ?? 'neutral') === 'rose'
        ? 'min-w-0 flex-1 rounded-lg border border-rose-300 bg-white px-3 py-2 text-sm text-[#1E1E1E] focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-400'
        : 'min-w-0 flex-1 rounded-lg border border-[#E0D6C2] bg-white px-3 py-2 text-sm text-[#1E1E1E] focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]';
    $buttonClass = ($tone ?? 'neutral') === 'rose'
        ? 'rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800 disabled:opacity-60'
        : 'rounded-lg bg-[#C9A227] px-4 py-2 text-sm font-semibold text-white hover:bg-[#b8931f] disabled:opacity-60';
@endphp

<div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-start">
    <input type="password"
        wire:model="tokenInput"
        autocomplete="off"
        placeholder="Paste new FACEBOOK_PAGE_ACCESS_TOKEN"
        class="{{ $inputClass }}">
    <button type="button"
        wire:click="saveToken"
        wire:loading.attr="disabled"
        class="{{ $buttonClass }}">
        Save token
    </button>
</div>

@if ($feedback)
    <p class="mt-2 text-xs {{ $feedbackOk ? 'text-emerald-700' : 'text-rose-700' }}">{{ $feedback }}</p>
@endif
