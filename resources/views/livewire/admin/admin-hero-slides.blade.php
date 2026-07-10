<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="font-serif text-3xl font-semibold">Hero Slides</h1>
            <p class="mt-1 text-sm text-[#8C8474]">Homepage banner carousel.</p>
        </div>
        <a href="{{ route('admin.hero-slides.create') }}" wire:navigate
            class="rounded-full bg-[#C9A227] px-5 py-2 text-sm font-semibold text-white hover:bg-[#b8931f]">
            Create Slide
        </a>
    </div>

    <div class="rounded-xl border border-[#EFE7D6] bg-white overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-[#FAF6EF] text-left text-[#6B6459]">
                    <tr>
                        <th class="px-4 py-3 font-medium w-24"></th>
                        <th class="px-4 py-3 font-medium">Title</th>
                        <th class="px-4 py-3 font-medium">Order</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E7DFCF]">
                    @forelse ($slides as $slide)
                        <tr class="hover:bg-[#FAF6EF]/60" wire:key="hero-{{ $slide->id }}">
                            <td class="px-4 py-3">
                                @if ($img = \App\Support\StorefrontAssets::url($slide->image))
                                    <img src="{{ $img }}" alt="" class="h-12 w-20 rounded object-cover border border-[#E7DFCF] bg-[#FAF6EF]">
                                @else
                                    <div class="h-12 w-20 rounded border border-[#E7DFCF] bg-[#FAF6EF]"></div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $slide->title }}</div>
                                @if ($slide->subtitle)
                                    <div class="text-xs text-[#8C8474] line-clamp-1">{{ $slide->subtitle }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 tabular-nums">{{ $slide->display_order }}</td>
                            <td class="px-4 py-3">
                                <button type="button" wire:click="togglePublished({{ $slide->id }})"
                                    class="text-xs rounded-full px-2.5 py-1 {{ $slide->is_published ? 'bg-emerald-50 text-emerald-700' : 'bg-[#FAF6EF] text-[#8C8474]' }}">
                                    {{ $slide->is_published ? 'Published' : 'Draft' }}
                                </button>
                            </td>
                            <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                                <a href="{{ route('admin.hero-slides.edit', $slide) }}" wire:navigate class="text-[#C9A227] hover:underline">Edit</a>
                                <button type="button"
                                    wire:click="delete({{ $slide->id }})"
                                    wire:confirm="Delete this hero slide?"
                                    class="text-rose-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-[#8C8474]">No hero slides yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
