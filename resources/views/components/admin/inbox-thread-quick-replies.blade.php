@props([
    'quickReplies' => [],
    'latestInboundMatchId' => null,
])

@php
    $hasMatch = is_string($latestInboundMatchId) && $latestInboundMatchId !== '';
@endphp

<div
    class="relative"
    x-data="{ open: false, top: 0, left: 0, ignoreOutside: false }"
    @keydown.escape.window="open = false"
>
    <button type="button"
        data-inbox-quick-reply-menu-trigger
        @click="
            open = ! open;
            ignoreOutside = true;
            if (open) {
                const rect = $el.getBoundingClientRect();
                const width = 240;
                top = rect.bottom + 4;
                left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8));
            }
            $nextTick(() => ignoreOutside = false);
        "
        class="relative inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-[#E0D6C2] bg-white text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]"
        x-bind:class="{ 'border-[#C9A227] text-[#C9A227]': open }"
        x-bind:aria-expanded="open.toString()"
        aria-haspopup="menu"
        title="Quick replies"
        aria-label="Quick replies">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5" aria-hidden="true">
            <path fill-rule="evenodd" d="M10 3c-4.31 0-8 3.033-8 7 0 2.024.978 3.825 2.499 5.085a3.478 3.478 0 0 1-.522 1.756.75.75 0 0 0 .584 1.143 5.976 5.976 0 0 0 3.936-1.108c.542.142 1.112.22 1.703.22 4.31 0 8-3.033 8-7s-3.69-7-8-7Zm0 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm-2-1a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm5 1a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
        </svg>
        @if ($hasMatch)
            <span class="absolute right-0.5 top-0.5 h-2 w-2 rounded-full bg-emerald-600" aria-hidden="true"></span>
        @endif
    </button>
    {{-- Thread pane is overflow-hidden; teleport so the menu is not clipped. --}}
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-bind:style="'position:fixed;top:' + top + 'px;left:' + left + 'px;z-index:80'"
            @click.outside="if (! ignoreOutside) open = false"
            x-transition.opacity.duration.100ms
            role="menu"
            data-inbox-quick-replies
            data-inbox-quick-reply-menu
            class="max-h-[min(24rem,70vh)] w-[15rem] overflow-y-auto rounded-xl border border-[#E7DFCF] bg-white p-1.5 text-left text-[#1E1E1E] shadow-lg"
        >
            @forelse ($quickReplies as $quickReply)
                <button type="button"
                    role="menuitem"
                    data-inbox-quick-reply
                    data-inbox-quick-reply-id="{{ $quickReply['id'] }}"
                    data-inbox-quick-reply-body="{{ $quickReply['body'] }}"
                    @click="
                        open = false;
                        const body = {{ \Illuminate\Support\Js::from($quickReply['body']) }};
                        $wire.$set('replyText', body, false);
                        const ta = document.querySelector('[data-inbox-reply-text]');
                        if (ta) {
                            ta.rows = Math.max(1, Math.min(8, (String(body).match(/\n/g) || []).length + 1));
                            ta.focus();
                        }
                    "
                    @class([
                        'block w-full rounded-lg px-2.5 py-1.5 text-left text-xs font-semibold hover:bg-[#FAF6EF]',
                        'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-600' => $latestInboundMatchId === $quickReply['id'],
                        'text-[#1E1E1E]' => $latestInboundMatchId !== $quickReply['id'],
                    ])>
                    {{ $quickReply['label'] }}
                </button>
            @empty
                <p class="px-2.5 py-1.5 text-xs text-[#8C8474]">No quick replies yet.</p>
            @endforelse
            <a href="{{ route('admin.inbox.quick-replies') }}" wire:navigate
                role="menuitem"
                class="mt-0.5 block rounded-lg px-2.5 py-1.5 text-xs font-semibold text-[#8C8474] hover:bg-[#FAF6EF] hover:text-[#C9A227]"
                @click="open = false">
                {{ empty($quickReplies) ? 'Add quick replies' : 'Edit' }}
            </a>
        </div>
    </template>
</div>
