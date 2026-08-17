@php
    $isModeratorOnly = auth()->user()?->isModeratorOnly() ?? false;
    $button = 'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:bg-[#FAF6EF] hover:text-[#C9A227] transition';
@endphp

<div {{ $attributes->class('flex items-center gap-2') }}>
    @unless ($isModeratorOnly)
        <a href="{{ route('admin.products') }}"
            wire:navigate
            class="{{ $button }}"
            aria-label="Products"
            title="Products">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                <path d="M10.362 1.093a.75.75 0 0 0-.724 0L2.523 5.018a.75.75 0 0 0 0 1.326l6.726 3.863a2.25 2.25 0 0 0 2.156 0l6.726-3.863a.75.75 0 0 0 0-1.326L10.362 1.093ZM2.25 8.84v5.662c0 .536.288 1.03.755 1.3l6.5 3.75a.75.75 0 0 0 .745 0l6.5-3.75a1.5 1.5 0 0 0 .755-1.3V8.84l-5.974 3.432a3.75 3.75 0 0 1-3.592 0L2.25 8.84Z" />
            </svg>
        </a>
    @endunless

    <a href="{{ route('admin.orders.new') }}"
        wire:navigate
        class="{{ $button }}"
        aria-label="Orders"
        title="Orders">
        <span class="text-xl leading-none font-semibold">+</span>
    </a>

    @unless ($isModeratorOnly)
        <a href="{{ route('admin.inbox') }}"
            wire:navigate
            class="{{ $button }}"
            aria-label="Inbox"
            title="Inbox">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
                <path d="M2.5 5.75A2.25 2.25 0 0 1 4.75 3.5h10.5A2.25 2.25 0 0 1 17.5 5.75v8.5a2.25 2.25 0 0 1-2.25 2.25H4.75A2.25 2.25 0 0 1 2.5 14.25v-8.5Zm2.68-.75a.75.75 0 0 0-.53 1.28l4.82 4.82a.75.75 0 0 0 1.06 0l4.82-4.82A.75.75 0 0 0 14.82 5H5.18Z" />
            </svg>
        </a>
    @endunless
</div>
