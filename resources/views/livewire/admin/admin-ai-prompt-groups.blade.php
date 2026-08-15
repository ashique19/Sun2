<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-serif text-3xl font-semibold">AI Prompt Groups</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Named sequences of edit steps for product image generation (e.g. extract → recolour → rotate).
            </p>
        </div>
        <a href="{{ route('admin.ai-prompts.create') }}" wire:navigate
            class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
            Create Group
        </a>
    </div>

    @if ($error)
        <div class="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $error }}</div>
    @endif

    <div class="space-y-4">
        @forelse ($groups as $group)
            <div class="rounded-xl border border-[#EFE7D6] bg-white p-5" wire:key="ai-prompt-group-{{ $group->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold text-lg">{{ $group->name }}</h2>
                        @if ($group->description)
                            <p class="mt-1 text-sm text-[#8C8474]">{{ $group->description }}</p>
                        @endif
                        <p class="mt-1 text-xs text-[#8C8474]">{{ $group->prompts_count }} step{{ $group->prompts_count === 1 ? '' : 's' }}</p>
                    </div>
                    <div class="flex flex-wrap gap-3 text-sm">
                        <a href="{{ route('admin.ai-prompts.edit', $group) }}" wire:navigate
                            class="text-[#C9A227] hover:underline">Edit</a>
                        <button type="button"
                            wire:click="delete({{ $group->id }})"
                            wire:confirm="Delete “{{ $group->name }}” and its steps?"
                            class="text-rose-600 hover:underline">Delete</button>
                    </div>
                </div>
                @if ($group->prompts->isNotEmpty())
                    <ol class="mt-4 space-y-2 border-t border-[#EFE7D6] pt-4">
                        @foreach ($group->prompts as $index => $prompt)
                            <li class="flex gap-3 text-sm">
                                <span class="tabular-nums text-[#8C8474]">{{ $index + 1 }}.</span>
                                <span class="text-[#1E1E1E]">{{ $prompt->prompt }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-[#EFE7D6] bg-white px-4 py-10 text-center text-sm text-[#8C8474]">
                No prompt groups yet. Create one to reuse edit sequences while generating product images.
            </div>
        @endforelse
    </div>
</div>
