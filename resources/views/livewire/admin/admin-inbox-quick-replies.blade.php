<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-serif text-3xl font-semibold text-[#1E1E1E]">Quick replies</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Composer chips in Inbox, plus reply tags used to track customer messages and suggest these replies.
            </p>
        </div>
        <a href="{{ route('admin.inbox') }}" wire:navigate
            class="rounded-full border border-[#E0D6C2] bg-white px-4 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
            Back to Inbox
        </a>
    </div>

    @if ($statusMessage)
        <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ $statusMessage }}</p>
    @endif
    @if ($error)
        <p class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $error }}</p>
    @endif

    <div class="space-y-3 rounded-2xl border border-[#EFE7D6] bg-white p-4 sm:p-5">
        @foreach ($replies as $index => $reply)
            <div wire:key="quick-reply-row-{{ $index }}" class="rounded-xl border border-[#E7DFCF] bg-[#FAF6EF]/60 p-3 sm:p-4">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Reply {{ $index + 1 }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="moveUp({{ $index }})"
                            class="rounded-full border border-[#E0D6C2] bg-white px-2.5 py-1 text-[11px] font-semibold text-[#6B6459] hover:border-[#C9A227]"
                            @disabled($index === 0)>
                            Up
                        </button>
                        <button type="button" wire:click="moveDown({{ $index }})"
                            class="rounded-full border border-[#E0D6C2] bg-white px-2.5 py-1 text-[11px] font-semibold text-[#6B6459] hover:border-[#C9A227]"
                            @disabled($index === count($replies) - 1)>
                            Down
                        </button>
                        <button type="button" wire:click="removeRow({{ $index }})"
                            class="rounded-full border border-rose-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-rose-600 hover:bg-rose-50">
                            Remove
                        </button>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-[12rem_1fr]">
                    <input type="hidden" wire:model="replies.{{ $index }}.id">
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Label</label>
                        <input type="text"
                            wire:model="replies.{{ $index }}.label"
                            maxlength="40"
                            placeholder="e.g. Address?"
                            class="w-full rounded-xl border border-[#E0D6C2] bg-white px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Body</label>
                        <textarea
                            wire:model="replies.{{ $index }}.body"
                            rows="2"
                            maxlength="2000"
                            placeholder="Text inserted into the reply composer"
                            class="w-full rounded-xl border border-[#E0D6C2] bg-white px-3 py-2 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]"></textarea>
                    </div>
                </div>
                @php $replyId = trim((string) ($reply['id'] ?? '')); @endphp
                <div class="mt-3 rounded-xl border border-dashed border-[#E0D6C2] bg-white/70 p-3" data-inbox-quick-reply-tags>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#8C8474]">Reply tags</p>
                    <p class="mt-0.5 text-[11px] text-[#8C8474]">Customer wording that tracks and suggests this reply.</p>
                    @if ($replyId === '')
                        <p class="mt-2 text-xs text-[#8C8474]">Save this reply first to add tags.</p>
                    @else
                        @php $tags = $examplesByReply[$replyId] ?? collect(); @endphp
                        <div class="mt-2 space-y-2" data-inbox-quick-reply-tag-list>
                            @forelse ($tags as $tag)
                                <div wire:key="quick-reply-tag-{{ $tag->id }}" class="flex flex-wrap items-center gap-2" data-inbox-quick-reply-tag="{{ $tag->id }}">
                                    <input type="text"
                                        wire:model="tagEdits.{{ $tag->id }}"
                                        maxlength="2000"
                                        class="min-w-0 flex-1 rounded-xl border border-[#E0D6C2] bg-white px-3 py-1.5 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                                    <button type="button"
                                        wire:click="updateTag({{ $tag->id }})"
                                        class="rounded-full border border-[#E0D6C2] bg-white px-2.5 py-1 text-[11px] font-semibold text-[#6B6459] hover:border-[#C9A227]">
                                        Save
                                    </button>
                                    <button type="button"
                                        wire:click="deleteTag({{ $tag->id }})"
                                        wire:confirm="Remove this reply tag?"
                                        class="rounded-full border border-rose-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-rose-600 hover:bg-rose-50">
                                        Delete
                                    </button>
                                </div>
                            @empty
                                <p class="text-xs text-[#8C8474]">No tags yet. Add wording staff have seen, like “দাম কত”.</p>
                            @endforelse
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <input type="text"
                                wire:model="newTags.{{ $replyId }}"
                                wire:keydown.enter.prevent="addTag('{{ $replyId }}')"
                                maxlength="2000"
                                placeholder="e.g. দাম কত"
                                class="min-w-0 flex-1 rounded-xl border border-[#E0D6C2] bg-white px-3 py-1.5 text-sm focus:border-[#C9A227] focus:outline-none focus:ring-1 focus:ring-[#C9A227]">
                            <button type="button"
                                wire:click="addTag('{{ $replyId }}')"
                                class="rounded-full border border-[#C9A227] bg-[#FAF6EF] px-3 py-1.5 text-[11px] font-semibold text-[#C9A227] hover:bg-[#C9A227] hover:text-white">
                                Add tag
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach

        <div class="flex flex-wrap gap-2 pt-1">
            <button type="button" wire:click="addRow"
                class="rounded-full border border-[#E0D6C2] bg-white px-4 py-2 text-sm font-semibold text-[#6B6459] hover:border-[#C9A227] hover:text-[#C9A227]">
                Add reply
            </button>
            <button type="button" wire:click="save"
                class="rounded-full bg-[#C9A227] px-4 py-2 text-sm font-semibold text-white hover:bg-[#b89220]">
                Save
            </button>
            <button type="button" wire:click="resetDefaults" wire:confirm="Restore the default quick replies from config?"
                class="rounded-full border border-[#E0D6C2] bg-white px-4 py-2 text-sm font-semibold text-[#8C8474] hover:text-[#1E1E1E]">
                Reset defaults
            </button>
        </div>
    </div>
</div>
