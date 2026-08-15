<div>
    <a href="{{ route('admin.ai-prompts') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; AI Prompt Groups</a>
    <h1 class="mt-2 mb-6 font-serif text-3xl font-semibold">{{ $group?->name ?? 'Create AI Prompt Group' }}</h1>

    @if ($message)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ $message }}</div>
    @endif
    @if ($error)
        <div class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $error }}</div>
    @endif

    <form wire:submit="save" class="max-w-3xl space-y-5 rounded-xl border border-[#EFE7D6] bg-white p-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium">Group name</label>
                <input type="text" wire:model="name" placeholder="e.g. Clean catalogue shot"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="mb-1 block text-sm font-medium">Description (optional)</label>
                <textarea wire:model="description" rows="2"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm"
                    placeholder="When to use this sequence…"></textarea>
                @error('description') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Sort order</label>
                <input type="number" min="0" wire:model="sort_order"
                    class="w-full rounded-lg border border-[#E0D6C2] px-4 py-2 text-sm">
                @error('sort_order') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="space-y-3 border-t border-[#EFE7D6] pt-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold">Sequence steps</h2>
                    <p class="mt-0.5 text-xs text-[#8C8474]">Applied in order on the same product photo during Generate.</p>
                </div>
                <button type="button" wire:click="addStep"
                    class="rounded-full border border-[#C9A227] px-4 py-1.5 text-xs font-semibold text-[#C9A227] hover:bg-[#FAF6EF]">
                    Add step
                </button>
            </div>

            @error('steps') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

            <ul class="space-y-3">
                @foreach ($steps as $index => $step)
                    <li class="rounded-lg border border-[#EFE7D6] p-3" wire:key="step-row-{{ $index }}-{{ $step['id'] ?? 'new' }}">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs font-medium text-[#6B6459]">Step {{ $index + 1 }}</p>
                            <div class="flex flex-wrap gap-1">
                                <button type="button" wire:click="moveStepEarlier({{ $index }})"
                                    class="rounded border border-[#E0D6C2] px-2 py-1 text-xs hover:bg-[#FAF6EF]"
                                    @disabled($index === 0)>↑</button>
                                <button type="button" wire:click="moveStepLater({{ $index }})"
                                    class="rounded border border-[#E0D6C2] px-2 py-1 text-xs hover:bg-[#FAF6EF]"
                                    @disabled($index === count($steps) - 1)>↓</button>
                                <button type="button" wire:click="removeStep({{ $index }})"
                                    class="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700 hover:bg-rose-50">
                                    Remove
                                </button>
                            </div>
                        </div>
                        <textarea wire:model="steps.{{ $index }}.prompt" rows="2"
                            placeholder="e.g. Extract the jewellery onto a clean white background"
                            class="w-full rounded-lg border border-[#E0D6C2] px-3 py-2 text-sm"></textarea>
                        @error('steps.'.$index.'.prompt')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-2">
            <button type="submit" class="rounded-full bg-[#C9A227] px-8 py-2.5 text-sm font-semibold text-white hover:bg-[#b8931f]">
                {{ $group ? 'Save Group' : 'Create Group' }}
            </button>
            @if ($group)
                <button type="button" wire:click="delete" wire:confirm="Delete this prompt group?"
                    class="rounded-full border border-rose-300 px-6 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                    Delete
                </button>
            @endif
        </div>
    </form>
</div>
