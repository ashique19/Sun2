<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('admin.ai-prompts') }}" wire:navigate class="text-sm text-[#C9A227] hover:underline">&larr; AI Prompt Groups</a>
            <h1 class="mt-2 font-serif text-3xl font-semibold">Recent AI Prompts</h1>
            <p class="mt-1 text-sm text-[#8C8474]">
                Single prompts used during Generate with AI (ungrouped). Reusable sequences live under Prompt Groups.
            </p>
        </div>
        <a href="{{ route('admin.ai-prompts') }}" wire:navigate
            class="rounded-full border border-[#C9A227] px-5 py-2 text-sm font-semibold text-[#C9A227] hover:bg-[#FAF6EF]">
            Prompt Groups
        </a>
    </div>

    @if ($message)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ $message }}</div>
    @endif

    <div class="overflow-hidden rounded-xl border border-[#EFE7D6] bg-white">
        <table class="w-full text-sm">
            <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                <tr>
                    <th class="px-4 py-3 font-medium">Prompt</th>
                    <th class="px-4 py-3 font-medium">Uses</th>
                    <th class="px-4 py-3 font-medium">Last used</th>
                    <th class="px-4 py-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[#E7DFCF]">
                @forelse ($prompts as $prompt)
                    <tr class="hover:bg-[#FAF6EF]/60" wire:key="recent-prompt-{{ $prompt->id }}">
                        <td class="px-4 py-3 text-[#1E1E1E]">{{ $prompt->prompt }}</td>
                        <td class="px-4 py-3 tabular-nums text-[#8C8474]">{{ $prompt->use_count }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-[#8C8474]">
                            {{ $prompt->last_used_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button type="button"
                                wire:click="delete({{ $prompt->id }})"
                                wire:confirm="Remove this recent prompt?"
                                class="text-rose-600 hover:underline">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-[#8C8474]">No recent prompts yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
